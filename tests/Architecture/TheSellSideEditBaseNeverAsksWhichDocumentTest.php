<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The contract of `admin/_base/commercial_document_edit.html.twig` — the frame the order form, the quote
 * form and the invoice create screen all extend.
 *
 * The twin of TheSellSideViewBaseNeverAsksWhichDocumentTest, for the OTHER base. Four things are
 * pinned, all of them properties of the SOURCE rather than of any one rendered page, which is why
 * this is a scan and not a page fetch: a fourth sell-side edit screen added tomorrow is covered the
 * moment its template exists.
 *
 * ## 1. The frame never asks which document it is rendering
 *
 * This is the rule the whole componentisation was built on, and the one a shared template fails
 * quietly: one `{% if document == 'invoice' %}` re-creates, inside the shared file, exactly the
 * three-way divergence the file was written to end. So no Twig TAG or EXPRESSION in the base may
 * name a document. Markup is not scanned — `order-workspace-card` and `order-lines-section` are CSS
 * class names, symbols the relabel ruling already put out of scope, and renaming them is a
 * stylesheet change and not this file's business.
 *
 * The scan is proved against itself: the same extractor is run over `admin/order/form.html.twig`,
 * where the forbidden words are everywhere and SHOULD be, and is required to find them FIRST. An
 * absence assertion whose extractor silently returned nothing would otherwise pass forever (#627).
 *
 * ## 2. The fill points are a fixed, named list
 *
 * The blocks are where a child's markup lands. Renaming one silently empties a region of a screen
 * that SAVES: a child filling a block the base no longer declares renders nothing at all and raises
 * nothing, and on this base "nothing" can mean a form with no hidden identity fields, or no save
 * buttons. So the list and its order are written down here.
 *
 * ## 3. Every edit screen extends the frame and hands it its parameters
 *
 * `workspaceReady` decides whether the page is the customer chooser or the workspace, and `formId`
 * names the form the hero's buttons post by `form="..."`. A child that forgets either renders a
 * chooser where a form belongs, or a form no button outside it can submit.
 *
 * ## 4. The edit frame carries NO customisation skeleton, and that is deliberate
 *
 * "The user customization only affects view / edit is pretty much the same and set by us", and
 * "view formatting doesn't wipe data". The view base therefore carries `injection_point()` slots
 * and a stable slug per region; this one must not, because a movable region on a screen that saves
 * is a way to lose a field. The absence is paired with a positive control on the SAME scan against
 * the VIEW base, which does carry them — so a scan that had stopped matching anything could not
 * pass as the finding.
 */
final class TheSellSideEditBaseNeverAsksWhichDocumentTest extends TestCase
{
    private const BASE = 'templates/admin/_base/commercial_document_edit.html.twig';
    private const VIEW_BASE = 'templates/admin/_base/commercial_document_view.html.twig';

    /** The three EDIT screens. */
    private const CHILDREN = [
        'templates/admin/order/form.html.twig',
        'templates/admin/estimate/form.html.twig',
        'templates/admin/invoice/create.html.twig',
    ];

    /**
     * A word that names one of the three documents, or asks which one is being rendered. Whole
     * words only: `documentId` and `formId` are neither.
     */
    private const FORBIDDEN = '/\b(order|orders|salesOrder|estimate|estimates|quote|quotes|invoice|invoices|isOrder|isEstimate|isInvoice|isQuote|documentType|docType)\b/i';

    /**
     * The frame's fill points, in the order the base declares them.
     *
     * @var list<string>
     */
    private const BLOCKS = [
        'admin_body',
        'no_js_style',
        'no_js_noscript_style',
        'hero_heading',
        'hero_actions',
        'tab_nav',
        'form_attributes',
        'form_head',
        'top_actions',
        'context',
        'lines_toolbar',
        'lines_note',
        'lines_table',
        'lines_after_table',
        'lines_footer',
        'bottom_actions',
        'after_form',
        'page_end',
        'picker_step',
        'company_picker_options',
    ];

    public function testTheFrameNeverNamesADocumentInsideATwigTagOrExpression(): void
    {
        $tags = $this->twigTagsAndExpressions($this->read(self::BASE));

        // The positive control, on the same extractor and the same kind of file: the order's own
        // form is full of the words this scan looks for. If the extractor were broken — a changed
        // delimiter, a file that moved — this fails and the absence below stops being evidence.
        $childTags = $this->twigTagsAndExpressions($this->read('templates/admin/order/form.html.twig'));
        $childOffenders = array_values(array_filter(
            $childTags,
            static fn (string $tag): bool => preg_match(self::FORBIDDEN, $tag) === 1,
        ));
        self::assertNotSame(
            [],
            $childOffenders,
            'the extractor found no document name in admin/order/form.html.twig, where they are'
            . ' everywhere — so it is not reading what it thinks it is reading',
        );

        self::assertGreaterThan(
            20,
            \count($tags),
            'the frame yielded almost no Twig tags, so the absence below would be vacuous',
        );

        $offenders = array_values(array_filter(
            $tags,
            static fn (string $tag): bool => preg_match(self::FORBIDDEN, $tag) === 1,
        ));

        self::assertSame(
            [],
            $offenders,
            'the shared frame names a document. It takes parameters, fills blocks, or delegates to a'
            . ' template path — it never asks which of the three it is rendering.',
        );
    }

    public function testTheFramesFillPointsAreTheNamedListInTheDeclaredOrder(): void
    {
        preg_match_all('/\{%-?\s*block\s+([A-Za-z0-9_]+)/', $this->read(self::BASE), $matches);

        self::assertSame(
            self::BLOCKS,
            $matches[1],
            'the frame\'s blocks are where a child\'s markup lands, and a child filling a block the'
            . ' base no longer declares renders nothing and says nothing — on a screen that saves,'
            . ' that is a form with no hidden fields or no save buttons. Renaming or reordering one'
            . ' is a deliberate act, so it is written down here.',
        );
    }

    public function testEveryEditScreenExtendsTheFrameAndHandsItItsParameters(): void
    {
        foreach (self::CHILDREN as $path) {
            $source = $this->read($path);

            self::assertStringContainsString(
                "{% extends 'admin/_base/commercial_document_edit.html.twig' %}",
                $source,
                $path . ' does not extend the shared edit frame',
            );

            foreach (['workspaceReady', 'formId'] as $parameter) {
                self::assertMatchesRegularExpression(
                    '/\{%\s*set\s+' . $parameter . '\s*=/',
                    $source,
                    $path . ' never sets the frame\'s required `' . $parameter . '` parameter —'
                    . ' without `workspaceReady` the screen is a customer chooser forever, and'
                    . ' without `formId` the buttons in the hero post a form that has no name',
                );
            }
        }
    }

    /**
     * The ruling, pinned: the EDIT frame is fixed and ours. The VIEW base is the positive control
     * on the same two scans, because it genuinely carries both.
     */
    public function testTheEditFrameCarriesNoCustomisationSkeletonWhileTheViewFrameDoes(): void
    {
        // Comments off first, on both: the reasoning in a template is allowed to talk about the
        // mechanism it deliberately does not use, and the edit frame's own docblock does exactly
        // that. What is pinned here is the CODE.
        $edit = $this->withoutComments($this->read(self::BASE));
        $view = $this->withoutComments($this->read(self::VIEW_BASE));

        self::assertGreaterThan(
            0,
            substr_count($view, 'injection_point('),
            'the view frame has no injection slots, so finding none in the edit frame proves'
            . ' nothing — this scan is no longer reading what it thinks it is reading',
        );
        self::assertSame(
            0,
            substr_count($edit, 'injection_point('),
            'the edit frame offers a slot for somebody else\'s content. Edit is not customisable:'
            . ' a movable region on a screen that saves is a way to lose a field.',
        );

        self::assertMatchesRegularExpression(
            '/\{%\s*set\s+slotPrefix|slotPrefix\s*~/',
            $view,
            'the view frame no longer names its injection slots, so the absence below is vacuous',
        );
        self::assertDoesNotMatchRegularExpression(
            '/\bslotPrefix\b/',
            $edit,
            'the edit frame declares a slot prefix. It has no slots to name.',
        );
    }

    private function withoutComments(string $source): string
    {
        return (string) preg_replace('/\{#.*?#\}/s', '', $source);
    }

    /**
     * Every `{% ... %}` and `{{ ... }}` in a template, with `{# ... #}` comments removed first: the
     * reasoning about a screen is allowed to go on naming the three documents, and does.
     *
     * @return list<string>
     */
    private function twigTagsAndExpressions(string $source): array
    {
        $withoutComments = preg_replace('/\{#.*?#\}/s', '', $source);
        preg_match_all('/\{%.*?%\}|\{\{.*?\}\}/s', (string) $withoutComments, $matches);

        return $matches[0];
    }

    private function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
