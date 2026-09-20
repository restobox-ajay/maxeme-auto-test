# Self-serve demo instances on wildcard subdomains

**Status:** design settled, nothing implemented.
**Date:** 2026-08-12

## What this is for

Anyone should be able to try the app without us provisioning anything. A visitor asks for a demo,
gets their own isolated instance, and it disappears on its own. Target scale is about **50
concurrent instances**, each capped at **50 MB**.

The whole design turns on one fact, verified rather than assumed: **the host permits wildcard
subdomains, and DNS, vhost routing and TLS are all automatic underneath one.** That single fact
deleted roughly two thirds of the work the alternative design required, so it is recorded here in
full — including how it was verified — so nobody re-derives the evening it took to find.

## The decision

**One subdomain per instance.** `<id>.<app-host>` where `<id>` is an opaque token (a UUID or
similar; deliberately not a human label, since instances are anonymous). The `Host` header resolves
to a SQLite file. Nothing else identifies the instance.

The rejected alternative was `?loc=<id>` on a single shared host, which is where most of the design
discussion went before the wildcard was confirmed. It is described below only because knowing *why*
it lost is what stops it being reconsidered.

### What was verified, and how

Created `*.fvstexpress-app.dev.rp017.webhelplogin.com` in cPanel → Domains, doc root
`/home/fvstappd/app-root/public`. Then, against a hostname that has never existed anywhere:

```
$ dig +short abc123zzz.fvstexpress-app.dev.rp017.webhelplogin.com
170.249.209.242                          # wildcard DNS resolves

$ curl -s -o /dev/null -w '%{http_code}' https://abc123zzz.fvstexpress-app.dev.rp017.webhelplogin.com/
400                                       # reached the APP, not a shared default page

$ openssl s_client -connect ... | openssl x509 -noout -subject -ext subjectAltName
subject=CN = *.fvstexpress-app.dev.rp017.webhelplogin.com
DNS:*.fvstexpress-app.dev.rp017.webhelplogin.com, DNS:fvstexpress-app.dev.rp017.webhelplogin.com
```

AutoSSL had already issued a **Let's Encrypt wildcard certificate** covering both the wildcard and
the base host. No Cloudflare, no purchased domain, no cert management, and — the point of the whole
exercise — **no per-instance infrastructure work of any kind.**

The 400 was not infrastructure. It was Symfony's own `trusted_hosts` refusing an unrecognised
hostname (`HttpKernel.php:83`), which is the framework doing its job and is a one-line fix.

**One caveat, and it matters:** the wildcard above was created on the **fvstexpress** account.
cPanel policy can differ per plan, so this must be confirmed on whichever account hosts this app
before anything is built on it.

### A wrong turn worth recording

An earlier probe hit `abc123xyz.dev.rp021.webhelplogin.com` and got the server's shared default
page, which was taken as evidence that unregistered hostnames cannot reach the app. That test proved
nothing: `webhelplogin.com` is the hosting provider's own domain, not one in our account, so a
wildcard could never have existed on it. The lesson is narrow but expensive — **probe a domain the
account actually controls**, or the result is meaningless.

## What the subdomain approach dissolves

Every row here was a real problem under `?loc=` on a shared host, and costs nothing here.

| Problem | Why it disappears |
|---|---|
| Simultaneous sign-in to several instances | Cookies are host-scoped. Free. |
| Cross-instance identity confusion — a session from instance A refreshing against B's database and silently authenticating you as your namesake | Separate origins, separate cookie jars. Structurally impossible rather than merely guarded. |
| Custom session cookie name per instance, set before the firewall reads it | Not needed. |
| A UrlGenerator decorator re-appending the instance to every generated URL | Relative URLs stay on the host by themselves. |
| Carrying the instance through links, forms, redirects and emailed reset links | The hostname carries it everywhere, automatically. |
| Remember-me degrading to useless (one cookie, cleared by visiting another instance) | Works normally, per host. |
| `Vary` / cache poisoning from header-based tenancy | No header tenancy. |
| Cross-instance XSS between strangers sharing one origin | Different origins. This one genuinely mattered: demo users are anonymous strangers who can type into product descriptions. |
| "Which instance loads before login?" and "which of my four logins do I use?" | The hostname *is* the instance. Bookmarks, reset links and the back button all resolve correctly with no picker, no directory, no email-first router. |
| Compiled per-instance Doctrine connections and Messenger transports | None required. One connection, repointed per request. |
| Per-instance Stripe endpoints and signing secrets | Demos take no payments. |
| Backups | Disposable by definition. |

## What still has to be built

| Item | Notes |
|---|---|
| **Instance resolution** | Listener mapping `Host` → SQLite path. Same shape as the existing `AdminHostSubscriber`. Must decide what an unknown or expired host does. |
| **`trusted_hosts`** | Widen to the wildcard pattern. Keep it — it is already acting as the guard against unknown hosts resolving to something, which is why the probe returned 400 rather than silently serving. |
| **Location-less routes** | **The only item with real refactoring behind it.** The bare host and the expired-demo page must render with no tenant database connected — but `base.html.twig` calls `site_name()`, which reads `AppSetting` from the tenant database. These pages need a layout that touches nothing, and every `kernel.request` subscriber that hits the EntityManager unconditionally needs auditing. |
| **Uploads** | Per-instance directory. Seed images shared read-only, never copied — copying 293 files per instance is an inode problem at any scale worth having. |
| **Cache and rate-limiter keys** | Prefix with the instance id. Row 1 exists in every instance, so `ApiKeyAuthenticator`'s per-credential limiter would otherwise share one budget across instances — and the symptom (random 429s with nothing in that instance's logs) is nasty to diagnose. |
| **Logs** | Monolog processor stamping the instance. The database-backed logs (error, email, audit, job) partition themselves. |
| **Migrations** | Batch cron over ~50 files after deploy. Seconds at this scale — no lazy-on-access, no per-instance locking, no version check per request. |
| **CLI instance resolution** | An env var or `--instance` option. There is no `Host` header in the console, so migrate and cleanup commands need their own channel. |
| **Provisioning** | Generate id, copy the template, hand back the URL. |
| **TTL** | Hard cap since creation, plus an idle timeout. Track last-access by touching a marker, throttled the way `ApiKeyAuthenticator::recordUsage()` already throttles `last_used_at` — don't turn every read into a write. |
| **Cleanup cron** | Delete database and uploads past TTL; log what was removed. Filenames are the instance ids, so the directory is the registry — no separate index needed. |
| **Abuse caps** | Creations per IP per hour; 50 MB per instance; a per-file upload limit enforced **at upload time**, not only in the cleanup sweep, or one large file lands before anything notices. |

## Scale arithmetic

Measured on the dev box: database **93.6 MB**, uploads **117 MB across 293 files**.

| Template | 50 live instances |
|---|---|
| as-is, 94 MB | 4.7 GB |
| trimmed, ~20 MB | 1 GB |
| minimal seed, ~5 MB | 250 MB |

Inodes at 50 instances are negligible (~150 for the databases). Copying seed uploads per instance
would be ~15,000, which is survivable but pointless — share them.

At 50 concurrent slots with a two-hour idle timeout, throughput is roughly 20 instances an hour,
call it 400–500 demos a day. Far beyond what is needed to prove the idea.

**Note the upside of the small number:** at 50 instances a *realistic* template is affordable, so
demos can carry the real catalog with images rather than a stripped seed. A richer demo is the whole
point, and the 5000-instance version of this plan would have taken it away.

## Open decisions

| Question | Notes |
|---|---|
| Template: full or slimmed? | Depends on the account's disk quota, which still needs checking. |
| Demo email: suppressed entirely, or captured to `email_log`? | Log-only lets a visitor see the email feature working without anything leaving the box. |
| Bare host: marketing page, or the create-demo form? | |
| Expired instance: friendly "this demo has expired" page, or 404? | |
| Messenger: `sync://`, or keep async? | At 50 instances either works. `sync://` removes the queue entirely; a shared queue with the instance stamped on each message also scales fine. Pick on preference, not constraint. |

## Relationship to the import-queue plan

`docs/plans/2026-08-10-imports-onto-messenger-queue.md` assumes a single database. It is not wrong —
it describes the production instance — but `job_run`, the import lock, the hourly RIM guard and the
prune job are all per-instance concerns if demos ever run async. If demos use `sync://`, that plan
needs no amendment at all, which is one more argument for `sync://`.
