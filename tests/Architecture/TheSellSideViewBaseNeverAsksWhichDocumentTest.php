<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The contract of `admin/_base/commercial_document_view.html.twig` — the frame the order's, the quote's
 * and the invoice's VIEW screens all extend.
 *
 * Three things are pinned here, and all three are properties of the SOURCE rather than of any one
 * rendered page, which is why this is a scan and not a page fetch: a fourth sell-side document
 * added tomorrow is covered the moment its template exists.
 *
 * ## 1. The frame never asks which document it is rendering
 *
 * This is the rule the whole componentisation was built on, and the one a shared template fails
 * quietly: one `{% if document == 'invoice' %}` re-creates, inside the shared file, exactly the
 * three-way divergence the file was written to end. So no Twig TAG or EXPRESSION in the base may
 * name a document. Markup is not scanned — `order-detail-grid` and `order-detail-panel` are CSS
 * class names, symbols the relabel ruling already put out of scope, and renaming them is a
 * stylesheet change and not this file's business.
 *
 * The scan is proved against itself: the same extractor is run over `admin/order/detail.html.twig`,
 * where the forbidden words are everywhere and SHOULD be, and is required to find them. An absence
 * assertion whose extractor silently returned nothing would otherwise pass forever (#627).
 *
 * ## 2. The fill points are a fixed, named list
 *
 * The blocks are the frame's slugs — the names a stored layout will eventually use to say which
 * region moved or switched off. Renaming one silently breaks that future register and, today,
 * silently empties a region of a screen: a child filling a block the base no longer declares
 * renders nothing at all and raises nothing. So the list and its order are written down here.
 *
 * ## 3. Every detail screen extends the frame and hands it its parameters
 *
 * `document`, `slotPrefix`, `actionBarTemplate` and `actionBarContext` are the base's four required
 * parameters. A child that forgets one renders a frame with a missing action bar or an injection
 * slot named `_after_summary` with nothing in front of it.
 */
final class TheSellSideViewBaseNeverAsksWhichDocumentTest extends TestCase
{
    private const BASE = 'templates/admin/_base/commercial_document_view.html.twig';

    /** The three VIEW screens, and the value each hands the frame as its injection-slot prefix. */
    private const CHILDREN = [
        'templates/admin/order/detail.html.twig' => 'admin_order_detail',
        'templates/admin/estimate/detail.html.twig' => 'admin_estimate_detail',
        'templates/admin/invoice/detail.html.twig' => 'admin_invoice_detail',
    ];

    /**
     * A word that names one of the three documents, or asks which one is being rendered. Whole
     * words only: `documentId` and `slotPrefix` are neither.
     */
    private const FORBIDDEN = '/\b(order|orders|salesOrder|estimate|estimates|quote|quotes|invoice|invoices|isOrder|isEstimate|isInvoice|isQuote|documentType|docType)\b/i';

    /**
     * The frame's fill points, in the order the base declares them. `totals` is declared first
     * because it is CAPTURED first — see the base — and rendered at its fixed position further
     * down; every other entry is declared where it renders.
     *
     * @var list<string>
     */
    private const BLOCKS = [
        'admin_body',
        'totals',
        'hero_eyebrow',
        'hero_title',
        'hero_status',
        'hero_actions',
        'summary_info_heading',
        'summary_info_status',
        'summary_info_rows',
        'summary_billing',
        'summary_shipping',
        'document_actions',
        'line_items_heading',
        'line_items_table',
        'stock_overrides',
        'add_message',
        'related_documents',
        'activity_log',
        'change_history',
        'page_end',
    ];

    public function testTheFrameNeverNamesADocumentInsideATwigTagOrExpression(): void
    {
        $tags = $this->twigTagsAndExpressions($this->read(self::BASE));

        // The positive control, on the same extractor and the same kind of file: the order's own
        // screen is full of the words this scan looks for. If the extractor were broken — a changed
        // delimiter, a file that moved — this fails and the absence below stops being evidence.
        $childTags = $this->twigTagsAndExpressions($this->read('templates/admin/order/detail.html.twig'));
        $childOffenders = array_values(array_filter(
            $childTags,
            static fn (string $tag): bool => preg_match(self::FORBIDDEN, $tag) === 1,
        ));
        self::assertNotSame(
            [],
            $childOffenders,
            'the extractor found no document name in admin/order/detail.html.twig, where they are'
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
            'the frame\'s blocks ARE its slugs: a future stored layout names regions by them, and a'
            . ' child filling a block the base no longer declares renders nothing and says nothing.'
            . ' Renaming or reordering one is a deliberate act, so it is written down here.',
        );
    }

    public function testEveryDetailScreenExtendsTheFrameAndHandsItItsParameters(): void
    {
        foreach (self::CHILDREN as $path => $slotPrefix) {
            $source = $this->read($path);

            self::assertStringContainsString(
                "{% extends 'admin/_base/commercial_document_view.html.twig' %}",
                $source,
                $path . ' does not extend the shared view frame',
            );

            foreach (['document', 'actionBarTemplate', 'actionBarContext'] as $parameter) {
                self::assertMatchesRegularExpression(
                    '/\{%\s*set\s+' . $parameter . '\s*=/',
                    $source,
                    $path . ' never sets the frame\'s required `' . $parameter . '` parameter',
                );
            }

            self::assertMatchesRegularExpression(
                "/\{%\s*set\s+slotPrefix\s*=\s*'" . preg_quote($slotPrefix, '/') . "'\s*%\}/",
                $source,
                $path . ' must name its injection slots ' . $slotPrefix . '_* — the prefix is the'
                . ' public name of this screen\'s two predefined slots and is not free to drift',
            );
        }
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
