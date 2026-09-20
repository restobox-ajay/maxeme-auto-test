<?php

declare(strict_types=1);

namespace App\Tests\Asset;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the cart/checkout JavaScript in public/assets/js/app.js.
 *
 * Issue #200 retired the last of the client-side localStorage cart ("wcCart"). Nothing in the test
 * suite executes app.js — Codeception's functional suite drives the Symfony kernel, never a browser
 * — so deleting from that file is otherwise unverifiable: a sabotaged handler produces a green run.
 * These tests read app.js and the Twig templates as text and assert the two agree, which is the part
 * that can actually be checked without a JS runtime.
 *
 * Two directions are covered:
 *
 *  - The retired cart stays retired. Reintroducing a browser-side cart store, or a `cart_payload`
 *    parameter no controller in src/ reads, fails here.
 *  - The handlers that survived it still line up with the markup they drive: the server cart page's
 *    quantity stepper, the reorder button that POSTs to /cart/reorder (#143), and the order-payment
 *    form on customer/order/detail.html.twig, which is a live Stripe payment flow.
 */
final class CustomerCartJsTest extends TestCase
{
    private const APP_JS = __DIR__ . '/../../public/assets/js/app.js';
    private const TEMPLATE_DIRS = [
        __DIR__ . '/../../templates',
        __DIR__ . '/../../modules',
    ];

    /**
     * The store itself, its mutators, and the page renderer that read it. Matched as declarations
     * rather than bare words so the comments explaining the removal don't trip the assertion.
     */
    public static function retiredCartSymbolProvider(): iterable
    {
        foreach ([
            'readCart',
            'writeCart',
            'cartItemCount',
            'updateCartBadge',
            'addToCart',
            'setCartQty',
            'removeFromCart',
            'clearCart',
            'maxCartQtyForItem',
            'renderCartPage',
            'beginReorder',
            'ensureReorderModal',
            'closeReorderModal',
            'addOrderLinesToCart',
        ] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('retiredCartSymbolProvider')]
    public function testTheLocalStorageCartFunctionsAreGone(string $name): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/\bfunction\s+' . preg_quote($name, '/') . '\s*\(/',
            $this->appJs(),
            sprintf('%s() is back in app.js; the localStorage cart was retired in #200.', $name),
        );
    }

    public function testNoBrowserSideCartStoreRemains(): void
    {
        $js = $this->appJs();

        self::assertStringNotContainsString(
            'wcCart',
            $this->stripComments($js),
            'app.js references the retired "wcCart" localStorage key.',
        );

        self::assertDoesNotMatchRegularExpression(
            '/\bCART_KEY\b/',
            $this->stripComments($js),
            'app.js declares a cart storage key again.',
        );
    }

    /**
     * The browser-storage surface, pinned. Both survivors are checkout *preferences*, not a cart: a
     * coupon code and the last-selected shipping address id. A new key here is a deliberate decision
     * and should be made deliberately.
     */
    public function testTheOnlyLocalStorageKeysAreCheckoutPreferences(): void
    {
        preg_match_all(
            '/localStorage\.(?:get|set|remove)Item\(\s*[\'"]([^\'"]+)[\'"]/',
            $this->stripComments($this->appJs()),
            $matches,
        );

        $keys = array_values(array_unique($matches[1]));
        sort($keys);

        self::assertSame(
            ['customer_checkout_coupon_code', 'customer_checkout_ship_address_id'],
            $keys,
        );
    }

    /**
     * `cart_payload` was the localStorage cart serialised onto every checkout request. No controller
     * in src/ ever read it and no template ever rendered a field by that name, so it priced nothing —
     * it just gave the browser a way to disagree with the server about what was in the cart.
     */
    public function testNothingSendsACartPayloadParameter(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/[\'"]cart_payload[\'"]/',
            $this->appJs(),
            'app.js sends a cart_payload parameter again; nothing in src/ reads one.',
        );

        foreach ($this->phpSources() as $path => $source) {
            self::assertStringNotContainsString(
                'cart_payload',
                $source,
                sprintf('%s reads a cart_payload parameter; app.js no longer sends one.', $path),
            );
        }
    }

    /**
     * The generalisation of the deletion: app.js may only bind cart handlers to classes that some
     * template actually renders. This is what made the removed handlers provably dead — they hung off
     * .js-cart-rows / .js-cart-count / .js-cart-empty / .js-checkout-btn, which no template has ever
     * carried — and it fails again if an orphan is reintroduced.
     *
     * There are no known orphans left. `js-reorder` was one — the reorder buttons had been converted
     * to plain <form> POSTs (see testReorderIsAPlainFormPostToTheServerCart below), leaving its
     * handler bound to nothing — and #200 recorded it here rather than delete it, because that was a
     * separate change. The handler is gone as of #221, so the allowlist is empty and any orphan this
     * finds from now on is a real one.
     */
    public function testEveryCartHandlerSelectorIsRenderedByATemplate(): void
    {
        $knownOrphans = [];
        $markup = $this->templateMarkup();
        $orphans = [];

        foreach ($this->boundSelectorClasses() as $class) {
            if (!str_starts_with($class, 'js-cart-') && !str_starts_with($class, 'js-reorder')) {
                continue;
            }

            if (!preg_match('/\b' . preg_quote($class, '/') . '\b/', $markup)) {
                $orphans[] = $class;
            }
        }

        sort($orphans);

        self::assertSame(
            $knownOrphans,
            $orphans,
            'app.js binds cart handlers to classes no template renders.',
        );
    }

    /**
     * The server cart page's +/- buttons. They are a convenience only — the number input still works
     * by typing plus "Update Cart" — but they are live, and they share the .js-cart-qty-inc /
     * .js-cart-qty-dec class names with the client-rendered cart table that #200 deleted. Deleting the
     * wrong one of the two handler pairs silently breaks the stepper on /cart.
     */
    public function testTheServerCartPageQuantityStepperStillWorks(): void
    {
        $cartPage = (string) file_get_contents(__DIR__ . '/../../templates/customer/cart/index.html.twig');

        self::assertMatchesRegularExpression('/class="cart-qty-control"/', $cartPage);
        self::assertMatchesRegularExpression('/class="cart-qty-btn js-cart-qty-dec"/', $cartPage);
        self::assertMatchesRegularExpression('/class="cart-qty-btn js-cart-qty-inc"/', $cartPage);
        self::assertMatchesRegularExpression('/class="cart-qty" type="number"/', $cartPage);

        $handler = $this->handlerBodyFor("'.js-cart-qty-inc, .js-cart-qty-dec'");

        self::assertStringContainsString(
            ".closest('.cart-qty-control')",
            $handler,
            'The cart page stepper no longer walks up to .cart-qty-control.',
        );
        self::assertStringContainsString(
            ".find('.cart-qty')",
            $handler,
            'The cart page stepper no longer targets the .cart-qty number input.',
        );
    }

    /**
     * The add-to-cart modal's own stepper, which is a *different* handler pair from the cart page's
     * despite doing the same job — .js-modal-qty-inc / .js-modal-qty-dec over .js-modal-qty. Both
     * pairs sat next to a third, now-deleted pair that mutated the localStorage cart, so this pins
     * which selectors belong to which.
     */
    public function testTheAddToCartModalQuantityStepperStillWorks(): void
    {
        $modal = (string) file_get_contents(__DIR__ . '/../../templates/customer/catalog/_cart_qty_modal.html.twig');

        self::assertMatchesRegularExpression('/class="cart-qty-btn js-modal-qty-dec"/', $modal);
        self::assertMatchesRegularExpression('/class="cart-qty js-modal-qty"[^>]*name="qty"/', $modal);
        self::assertMatchesRegularExpression('/class="cart-qty-btn js-modal-qty-inc"/', $modal);

        $handler = $this->handlerBodyFor("'.js-modal-qty-inc, .js-modal-qty-dec'");

        self::assertStringContainsString(
            ".find('.js-modal-qty')",
            $handler,
            'The add-to-cart modal stepper no longer targets its own quantity input.',
        );
        self::assertStringContainsString(
            "hasClass('js-modal-qty-inc')",
            $handler,
            'The add-to-cart modal stepper no longer distinguishes increment from decrement.',
        );
    }

    /**
     * Reorder puts a past order's lines back into the server-side cart. It used to write to the
     * localStorage cart that /cart never read, so /cart came up empty (#143). The fix landed as a
     * plain <form> POST per reorder button — no JS involved — which is why deleting the localStorage
     * cart cannot break it. CustomerReorderCest covers the controller these post to.
     *
     * Every reorder button must stay a real submit inside such a form: an anchor or a JS-only button
     * would put the flow back on the client-side path #143 was about.
     */
    public function testReorderIsAPlainFormPostToTheServerCart(): void
    {
        $reorderTemplates = [
            __DIR__ . '/../../templates/customer/order/detail.html.twig',
            __DIR__ . '/../../templates/customer/order/_list_rows.html.twig',
        ];

        $forms = 0;

        foreach ($reorderTemplates as $path) {
            $markup = (string) file_get_contents($path);

            preg_match_all(
                '/<form[^>]*class="reorder-form"[^>]*>(.*?)<\/form>/s',
                $markup,
                $matches,
                PREG_SET_ORDER,
            );

            self::assertNotSame([], $matches, sprintf('%s renders no reorder form.', basename($path)));

            foreach ($matches as [$form, $body]) {
                $forms++;
                self::assertStringContainsString('method="post"', $form);
                self::assertStringContainsString("path('customer_cart_reorder')", $form);
                // Either spelling counts: a literal hidden input, or the global {{ csrf_field() }}
                // helper that renders exactly that input. Reorder moved from its own per-endpoint
                // 'customer_cart' token to the global one, so the literal is no longer in source —
                // what this guards is that the form still carries a token and is a real no-JS POST.
                self::assertMatchesRegularExpression(
                    '/name="_token"|csrf_field\(\)/',
                    $body,
                    'A reorder form carries no CSRF token, so the server will reject the POST.',
                );
                // The order id, not the lines: the server loads the order and reads its own lines,
                // which is what makes an ownership check possible at all (#230).
                self::assertStringContainsString('name="order"', $body);
                self::assertStringNotContainsString('name="lines"', $body);
                self::assertMatchesRegularExpression(
                    '/<button[^>]*type="submit"/',
                    $body,
                    'A reorder form has no real submit button, so it needs JS to fire.',
                );
            }
        }

        self::assertSame(3, $forms, 'The number of reorder forms changed; re-check they all still POST.');
    }

    /**
     * customer/order/detail.html.twig is the one and only #checkout-form in the codebase, and it is a
     * live Stripe payment flow. app.js branches the whole checkout on its data-order-payment="1" and
     * prices the panel off the persisted order's attributes, never a browser-side cart.
     */
    public function testOrderPaymentCheckoutReadsThePersistedOrder(): void
    {
        $js = $this->appJs();
        $detail = (string) file_get_contents(__DIR__ . '/../../templates/customer/order/detail.html.twig');

        self::assertSame(
            1,
            preg_match_all('/id="checkout-form"/', $this->templateMarkup()),
            'There is more than one #checkout-form; the order-payment branching in app.js assumes exactly one.',
        );

        self::assertStringContainsString('data-order-payment="1"', $detail);

        foreach (['data-order-subtotal', 'data-order-shipping', 'data-order-fee-total', 'data-order-tax-rate', 'data-order-id'] as $attribute) {
            self::assertStringContainsString($attribute, $detail, sprintf('The payment form no longer renders %s.', $attribute));
            self::assertStringContainsString($attribute, $js, sprintf('app.js no longer reads %s.', $attribute));
        }

        self::assertStringContainsString('function isOrderPaymentCheckout(', $js);
        self::assertStringContainsString('function orderPaymentSubtotal(', $js);
        self::assertStringContainsString('function requestStripeCheckoutIntent(', $js);
        self::assertStringContainsString("formData.append('order_id'", $js);
    }

    /** The coupon box on the payment page prices against the order, not a cart. */
    public function testCheckoutCouponPricesAgainstTheOrderSubtotal(): void
    {
        self::assertMatchesRegularExpression(
            '/function applyCheckoutCoupon\([^)]*\)\s*\{.*?isOrderPaymentCheckout\(\$form\)\s*\?\s*orderPaymentSubtotal\(\$form\)\s*:\s*0/s',
            $this->appJs(),
            'applyCheckoutCoupon() no longer takes its subtotal from the persisted order.',
        );
    }

    private function appJs(): string
    {
        static $js = null;

        if ($js === null) {
            $js = file_get_contents(self::APP_JS);
            self::assertIsString($js, 'app.js is unreadable.');
        }

        return (string) $js;
    }

    /**
     * Every class named inside a delegated `$(document).on(events, selector, handler)` binding.
     *
     * @return list<string>
     */
    private function boundSelectorClasses(): array
    {
        preg_match_all(
            '/\.on\(\s*\'[^\']+\'\s*,\s*\'([^\']+)\'\s*,/',
            $this->stripComments($this->appJs()),
            $matches,
        );

        $classes = [];
        foreach ($matches[1] as $selector) {
            if (preg_match_all('/\.([A-Za-z][\w-]*)/', $selector, $found)) {
                foreach ($found[1] as $class) {
                    $classes[$class] = true;
                }
            }
        }

        return array_keys($classes);
    }

    /** The source of the delegated handler registered for $selector, up to its closing `});`. */
    private function handlerBodyFor(string $selector): string
    {
        $js = $this->appJs();
        $needle = '.on(\'click\', ' . $selector . ',';
        $offset = strpos($js, $needle);

        self::assertIsInt($offset, sprintf('No delegated click handler is bound to %s.', $selector));

        $end = strpos($js, "\n    });", (int) $offset);
        self::assertIsInt($end, sprintf('Could not find the end of the %s handler.', $selector));

        return substr($js, (int) $offset, (int) $end - (int) $offset);
    }

    /** Every Twig template in the app and in the bundled modules, concatenated. */
    private function templateMarkup(): string
    {
        static $markup = null;

        if ($markup === null) {
            $parts = [];
            foreach ($this->twigFiles() as $path) {
                $parts[] = (string) file_get_contents($path);
            }
            $markup = implode("\n", $parts);
        }

        return (string) $markup;
    }

    /** @return list<string> */
    private function twigFiles(): array
    {
        $files = [];

        foreach (self::TEMPLATE_DIRS as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.html.twig')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /** @return array<string, string> */
    private function phpSources(): array
    {
        $sources = [];

        foreach ([__DIR__ . '/../../src', __DIR__ . '/../../modules'] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $sources;
    }

    /**
     * Drops comments so the prose explaining a removal cannot satisfy a test looking for the thing
     * that was removed. Tracks string and regex literals, since `//` inside "https://…" or a regex
     * character class is not a comment.
     */
    private function stripComments(string $js): string
    {
        $out = '';
        $length = strlen($js);
        $i = 0;
        $previousSignificant = '';

        while ($i < $length) {
            $char = $js[$i];
            $next = $i + 1 < $length ? $js[$i + 1] : '';

            if ($char === '/' && $next === '*') {
                $end = strpos($js, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;
                $out .= ' ';
                continue;
            }

            if ($char === '/' && $next === '/') {
                $end = strpos($js, "\n", $i);
                $i = $end === false ? $length : $end;
                continue;
            }

            if ($char === '\'' || $char === '"' || $char === '`') {
                $end = $this->endOfLiteral($js, $i, $char);
                $out .= substr($js, $i, $end - $i);
                $i = $end;
                $previousSignificant = $char;
                continue;
            }

            // A `/` starts a regex literal only where a value is expected, never after one.
            if ($char === '/' && ($previousSignificant === '' || str_contains('(,=:[!&|?{};+-*%~^', $previousSignificant))) {
                $end = $this->endOfLiteral($js, $i, '/');
                $out .= substr($js, $i, $end - $i);
                $i = $end;
                $previousSignificant = '/';
                continue;
            }

            if (!ctype_space($char)) {
                $previousSignificant = $char;
            }

            $out .= $char;
            $i++;
        }

        return $out;
    }

    /** Offset just past the literal opened by $delimiter at $start, honouring backslash escapes. */
    private function endOfLiteral(string $js, int $start, string $delimiter): int
    {
        $length = strlen($js);
        $inClass = false;

        for ($i = $start + 1; $i < $length; $i++) {
            $char = $js[$i];

            if ($char === '\\') {
                $i++;
                continue;
            }

            if ($delimiter === '/') {
                if ($char === '[') {
                    $inClass = true;
                    continue;
                }
                if ($char === ']') {
                    $inClass = false;
                    continue;
                }
                if ($inClass) {
                    continue;
                }
            }

            if ($char === $delimiter) {
                return $i + 1;
            }

            // An unescaped newline ends a single-quoted/double-quoted string or a regex literal.
            if ($char === "\n" && $delimiter !== '`') {
                return $i;
            }
        }

        return $length;
    }
}
