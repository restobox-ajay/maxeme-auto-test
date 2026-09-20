#!/usr/bin/env bash
#
# Headless Claude Code loop that writes unit/integration/Codeception tests one target at a
# time. Each iteration is a fresh `claude -p` process with no memory of prior iterations —
# coordination happens entirely through git history and tests/TEST_LOOP_LOG.md, which each
# iteration reads and appends to before committing.
#
# Usage:
#   scripts/test-loop/run-test-loop.sh [options]
#
#   --iterations N   how many loop iterations to run (default: 15)
#   --mode MODE      all | unit | functional | modules (default: all)
#   --branch NAME    branch to run on (default: auto-created test-loop/<timestamp> off the
#                    current branch, unless already off main and TEST_LOOP_ALLOW_MAIN=1)
#   --account NAME   which logged-in Claude subscription to use for this run:
#                      default  -> ~/.claude          (whatever `claude` normally uses)
#                      work     -> ~/.claude-work
#                      work1    -> ~/.claude-work1
#                      <path>   -> any explicit CLAUDE_CONFIG_DIR path
#                    Lets two loop instances run in parallel against two different
#                    subscriptions without fighting over the same rate limit.
#   -h, --help       show this help and exit
#
#   Each also accepts --opt=value form. Example:
#     scripts/test-loop/run-test-loop.sh --mode unit --account work1
#
# Env overrides:
#   TEST_LOOP_BUDGET_USD    max USD spend per iteration        (default: 2)
#   TEST_LOOP_MODEL         --model passthrough                (default: unset, inherits account default)
#   TEST_LOOP_SLEEP_SECS    pause between iterations           (default: 15)
#   TEST_LOOP_MAX_FAILURES  consecutive FAILED before abort     (default: 3)
#   TEST_LOOP_ALLOW_MAIN    set to 1 to allow running on main/master directly
#
# Requires the `claude` CLI on PATH. Runs with a curated --allowedTools list (read/edit files,
# phpunit/codecept/composer, and a narrow set of git subcommands) rather than
# --dangerously-skip-permissions, so it can run unattended without ever prompting.

set -euo pipefail

usage() {
    sed -n '3,26p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

ITERATIONS=15
MODE=all
REQUESTED_BRANCH=""
ACCOUNT=default

while [[ $# -gt 0 ]]; do
    case "$1" in
        --iterations) ITERATIONS="$2"; shift 2 ;;
        --iterations=*) ITERATIONS="${1#*=}"; shift ;;
        --mode) MODE="$2"; shift 2 ;;
        --mode=*) MODE="${1#*=}"; shift ;;
        --branch) REQUESTED_BRANCH="$2"; shift 2 ;;
        --branch=*) REQUESTED_BRANCH="${1#*=}"; shift ;;
        --account) ACCOUNT="$2"; shift 2 ;;
        --account=*) ACCOUNT="${1#*=}"; shift ;;
        -h|--help) usage; exit 0 ;;
        *)
            echo "Unknown option: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

case "$ACCOUNT" in
    default|claude)
        CLAUDE_CONFIG_DIR=""
        ;;
    work|work1)
        CLAUDE_CONFIG_DIR="$HOME/.claude-$ACCOUNT"
        ;;
    *)
        CLAUDE_CONFIG_DIR="$ACCOUNT"
        ;;
esac

if [[ -n "$CLAUDE_CONFIG_DIR" ]]; then
    if [[ ! -d "$CLAUDE_CONFIG_DIR" ]]; then
        echo "Claude account config dir not found: $CLAUDE_CONFIG_DIR" >&2
        exit 1
    fi
    export CLAUDE_CONFIG_DIR
fi

BUDGET_USD="${TEST_LOOP_BUDGET_USD:-2}"
MODEL="${TEST_LOOP_MODEL:-}"
SLEEP_SECS="${TEST_LOOP_SLEEP_SECS:-15}"
MAX_FAILURES="${TEST_LOOP_MAX_FAILURES:-3}"
ALLOW_MAIN="${TEST_LOOP_ALLOW_MAIN:-0}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
PROMPTS_DIR="$SCRIPT_DIR/prompts"
LOG_DIR="$SCRIPT_DIR/logs"

case "$MODE" in
    all|unit|functional|modules) ;;
    *)
        echo "Unknown mode '$MODE' — expected one of: all unit functional modules" >&2
        exit 1
        ;;
esac

TARGET_PROMPT_FILE="$PROMPTS_DIR/target-$MODE.md"
CONVENTIONS_FILE="$PROMPTS_DIR/conventions.md"
if [[ ! -f "$TARGET_PROMPT_FILE" || ! -f "$CONVENTIONS_FILE" ]]; then
    echo "Missing prompt file(s) under $PROMPTS_DIR" >&2
    exit 1
fi

if ! command -v claude >/dev/null 2>&1; then
    echo "claude CLI not found on PATH" >&2
    exit 1
fi

cd "$REPO_ROOT"

if ! git diff --quiet || ! git diff --cached --quiet; then
    echo "Working tree has uncommitted changes — commit or stash before running the loop." >&2
    exit 1
fi

CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [[ -n "$REQUESTED_BRANCH" ]]; then
    git checkout -B "$REQUESTED_BRANCH"
elif [[ "$CURRENT_BRANCH" == "main" || "$CURRENT_BRANCH" == "master" ]] && [[ "$ALLOW_MAIN" != "1" ]]; then
    NEW_BRANCH="test-loop/$(date +%Y%m%d-%H%M%S)"
    echo "On $CURRENT_BRANCH — creating $NEW_BRANCH so loop commits stay isolated."
    git checkout -b "$NEW_BRANCH"
fi

mkdir -p "$LOG_DIR"
RUN_LOG="$LOG_DIR/run-$(date +%Y%m%d-%H%M%S)-$MODE.log"
START_SHA="$(git rev-parse HEAD)"

PROMPT="$(cat "$CONVENTIONS_FILE" "$TARGET_PROMPT_FILE")"

ALLOWED_TOOLS=(
    "Read" "Glob" "Grep" "Write" "Edit"
    "Bash(vendor/bin/phpunit *)"
    "Bash(vendor/bin/codecept *)"
    "Bash(composer *)"
    "Bash(git status*)"
    "Bash(git diff*)"
    "Bash(git log*)"
    "Bash(git add *)"
    "Bash(git commit *)"
)

echo "Test loop starting: mode=$MODE iterations=$ITERATIONS branch=$(git rev-parse --abbrev-ref HEAD) budget/iter=\$${BUDGET_USD} account=${ACCOUNT}${CLAUDE_CONFIG_DIR:+ ($CLAUDE_CONFIG_DIR)}"
echo "Run log: $RUN_LOG"

fail_streak=0
bug_count=0
for ((i = 1; i <= ITERATIONS; i++)); do
    echo
    echo "=== Iteration $i/$ITERATIONS ($(date '+%Y-%m-%d %H:%M:%S')) ===" | tee -a "$RUN_LOG"

    set +e
    CLAUDE_ARGS=(-p "$PROMPT" --output-format text --max-budget-usd "$BUDGET_USD" --allowedTools "${ALLOWED_TOOLS[@]}")
    if [[ -n "$MODEL" ]]; then
        CLAUDE_ARGS+=(--model "$MODEL")
    fi
    OUTPUT="$(claude "${CLAUDE_ARGS[@]}" 2>&1)"
    CLAUDE_EXIT=$?
    set -e

    echo "$OUTPUT" | tee -a "$RUN_LOG"

    STATUS_LINE="$(echo "$OUTPUT" | grep -o 'LOOP_STATUS: [A-Z_]*' | tail -n1 || true)"
    BUG_LINE="$(echo "$OUTPUT" | grep -o 'BUG_FOUND: [A-Z]*' | tail -n1 || true)"

    if [[ "$CLAUDE_EXIT" -ne 0 ]]; then
        echo "claude exited non-zero ($CLAUDE_EXIT) — treating as a failed iteration." | tee -a "$RUN_LOG"
        STATUS_LINE="LOOP_STATUS: FAILED"
    fi

    if [[ "$BUG_LINE" == "BUG_FOUND: YES" ]]; then
        bug_count=$((bug_count + 1))
        echo ">>> BUG FOUND AND FIXED this iteration — see tests/TEST_LOOP_BUGS.md and commit $(git rev-parse --short HEAD) <<<" | tee -a "$RUN_LOG"
    fi

    case "$STATUS_LINE" in
        "LOOP_STATUS: COMMITTED")
            fail_streak=0
            ;;
        "LOOP_STATUS: NOTHING_LEFT")
            echo "Loop reports nothing left to test in mode '$MODE'. Stopping." | tee -a "$RUN_LOG"
            break
            ;;
        "LOOP_STATUS: FAILED")
            fail_streak=$((fail_streak + 1))
            echo "Iteration failed ($fail_streak/$MAX_FAILURES consecutive)." | tee -a "$RUN_LOG"
            if [[ "$fail_streak" -ge "$MAX_FAILURES" ]]; then
                echo "Hit $MAX_FAILURES consecutive failures — aborting for a human to look at the working tree/log." | tee -a "$RUN_LOG"
                break
            fi
            ;;
        *)
            fail_streak=$((fail_streak + 1))
            echo "No LOOP_STATUS sentinel found in output — treating as a failed iteration ($fail_streak/$MAX_FAILURES)." | tee -a "$RUN_LOG"
            if [[ "$fail_streak" -ge "$MAX_FAILURES" ]]; then
                echo "Hit $MAX_FAILURES consecutive failures — aborting." | tee -a "$RUN_LOG"
                break
            fi
            ;;
    esac

    if [[ "$i" -lt "$ITERATIONS" ]]; then
        sleep "$SLEEP_SECS"
    fi
done

echo
echo "=== Loop finished. Commits made this run: ===" | tee -a "$RUN_LOG"
git log --oneline "$START_SHA"..HEAD | tee -a "$RUN_LOG"
echo | tee -a "$RUN_LOG"
echo "Bugs found & fixed this run: $bug_count" | tee -a "$RUN_LOG"
if [[ "$bug_count" -gt 0 ]]; then
    echo "Review them in tests/TEST_LOOP_BUGS.md and in the 'fix:' commits above." | tee -a "$RUN_LOG"
fi
