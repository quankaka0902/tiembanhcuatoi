<?php

namespace App\Http\Controllers;

use App\Jobs\SendOrderEmail;
use App\Services\Branches;
use App\Services\Catalog;
use App\Services\Checkout;
use App\Services\OrderRequests;
use App\Services\Orders;
use App\Services\ProductMetadata;
use App\Services\PublicSeo;
use App\Services\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

class Storefront extends Controller
{
    public function selectBranch(Request $request)
    {
        abort_unless(Branches::enabled(), 404);
        $fields = $request->validate(['branch_id' => 'required|integer|min:1', 'confirm_clear' => 'sometimes|boolean']);
        $branch = Branches::find((int) $fields['branch_id']);
        abort_unless($branch && $branch->active, 404);
        $cartAtAnotherBranch = $request->session()->get('cart') && (int) $request->session()->get('cart_branch_id', 1) !== (int) $branch->id;
        if ((int) $branch->id !== Branches::id() || $cartAtAnotherBranch) {
            if ($request->session()->get('cart') && ! ($fields['confirm_clear'] ?? false)) {
                throw ValidationException::withMessages(['branch_id' => 'Đổi cơ sở sẽ xóa giỏ hiện tại. Hãy xác nhận trước khi đổi.']);
            }
            $request->session()->forget(['cart', 'cart_branch_id', 'checkout_key']);
            $request->session()->put('branch_id', (int) $branch->id);
            Branches::use((int) $branch->id);
        }

        return redirect('/thuc-don')->with('notice', 'Đã chọn cơ sở '.$branch->name.'. Giá, tồn và lịch nhận áp dụng theo cơ sở này.');
    }

    public function home()
    {
        return Inertia::render('Home', [
            'categories' => DB::table('categories')->orderBy('id')->get(),
            'featured' => Catalog::listings()->where('products.featured', true)->orderBy('products.id')->limit(4)->get()->map(Catalog::images(...)),
            'occasions' => Catalog::filterOptions()['occasions'], 'currentLocation' => Catalog::currentLocation(),
            'seo' => PublicSeo::home(),
        ]);
    }

    public function catalog(Request $request, ?string $categorySlug = null)
    {
        $rules = Catalog::filterRules();
        $rules['max'] = 'nullable|integer|min:0|max:100000000'.($request->filled('min') ? '|gte:min' : '');
        $filters = $request->validate($rules);
        $currentCategory = $categorySlug ? DB::table('categories')->where('slug', $categorySlug)->first(['id', 'name', 'slug']) : null;
        if ($categorySlug && ! $currentCategory) {
            $oldCategory = DB::table('category_slug_history')->join('categories', 'categories.id', '=', 'category_slug_history.category_id')
                ->where('category_slug_history.slug', $categorySlug)->first(['categories.slug']);
            $destination = $oldCategory ? PublicSeo::url('/thuc-don/'.$oldCategory->slug) : null;
            abort_unless($destination, 404);

            return redirect()->away($destination, 301);
        }
        if ($currentCategory) {
            $filters['category'] = $currentCategory->slug;
        } elseif (! empty($filters['category'])) {
            $currentCategory = DB::table('categories')->where('slug', $filters['category'])->first(['id', 'name', 'slug']);
            if ($currentCategory && ! array_diff(array_keys($request->query()), ['category', 'page'])) {
                $page = (int) ($filters['page'] ?? 1);
                $destination = PublicSeo::url('/thuc-don/'.$currentCategory->slug.($page > 1 ? '?page='.$page : ''));
                abort_unless($destination, 404);

                return redirect()->away($destination, 301);
            }
        }
        $query = Catalog::listings($filters);
        match ($filters['sort'] ?? 'featured') {
            'price_asc' => $query->orderByRaw('availability.price IS NULL')->orderBy('availability.price'),
            'price_desc' => $query->orderByDesc('availability.price'),
            'new' => $query->orderByDesc('products.created_at'),
            default => $query->orderByDesc('products.featured'),
        };
        $paginationFilters = $filters;
        if ($currentCategory) {
            unset($paginationFilters['category']);
        }
        $products = $query->orderBy('products.id')->paginate(8)
            ->withPath($currentCategory ? '/thuc-don/'.$currentCategory->slug : '/thuc-don')
            ->appends($paginationFilters)->through(Catalog::images(...));
        abort_if($products->currentPage() > $products->lastPage(), 404);
        $filtered = count(array_diff(array_keys($request->query()), ['page'])) > 0;

        return Inertia::render('Catalog', [
            'products' => $products, 'currentCategory' => $currentCategory,
            'categories' => DB::table('categories')->orderBy('id')->get(), 'filters' => $filters,
            'filterOptions' => Catalog::filterOptions(), 'currentLocation' => Catalog::currentLocation(),
            'seo' => PublicSeo::catalog($currentCategory, $products->currentPage(), $filtered || $products->total() === 0),
        ]);
    }

    public function product(Request $request, string $slug)
    {
        $product = DB::table('products')->join('categories', 'categories.id', '=', 'products.category_id')
            ->where('products.slug', $slug)->where('products.active', true)
            ->first(['products.*', 'categories.name as category', 'categories.slug as category_slug']);
        if (! $product) {
            $old = DB::table('product_slug_history')->join('products', 'products.id', '=', 'product_slug_history.product_id')
                ->where('product_slug_history.slug', $slug)->where('products.active', true)->first(['products.slug']);
            $destination = $old ? PublicSeo::url('/banh/'.$old->slug) : null;
            abort_unless($destination, 404);

            return redirect()->away($destination, 301);
        }
        if ($product->slug !== $slug) {
            $destination = PublicSeo::url('/banh/'.$product->slug);
            abort_unless($destination, 404);

            return redirect()->away($destination, 301);
        }
        $product = Catalog::images($product);
        $product->variants = Catalog::variants()->where('product_id', $product->id)
            ->orderBy('id')->get(['id', 'name', 'price', 'stock', 'size_label', 'servings_min', 'servings_max', 'servings_verified', ...(Branches::enabled() ? ['prep_minutes'] : [])]);
        $rules = Catalog::filterRules();
        $rules['max'] = 'nullable|integer|min:0|max:100000000'.($request->filled('min') ? '|gte:min' : '');
        $filters = $request->validate($rules);
        $suggestion = Catalog::variants($filters)->where('product_id', $product->id);
        if (($filters['availability'] ?? 'all') === 'available') {
            $suggestion->where('stock', '>', 0);
        } elseif (($filters['availability'] ?? 'all') === 'sold_out') {
            $suggestion->where('stock', 0);
        }
        $product->accessories = ProductMetadata::accessories($product->id);

        return Inertia::render('Product', ['product' => $product, 'suggestedVariantId' => $suggestion->orderByRaw('stock > 0 DESC')->orderBy('price')->orderBy('id')->value('id'), 'selectionFilters' => $filters, 'seo' => PublicSeo::product($product)]);
    }

    public function cart(Request $request, Checkout $checkout)
    {
        $this->guardCartBranch($request);

        return Inertia::render('Cart', ['lines' => $checkout->cart($request->session()->get('cart', []))]);
    }

    public function saveLine(Request $request, Checkout $checkout, ?string $line = null)
    {
        $this->guardCartBranch($request);
        $cart = $request->session()->get('cart', []);
        abort_if($line !== null && ! isset($cart[$line]), 404);
        $data = $request->validate([
            'variant_id' => 'required|integer|exists:variants,id', 'quantity' => 'required|integer|min:1|max:30',
            'message' => 'nullable|string|max:60', 'note' => 'nullable|string|max:250',
            'accessories' => 'sometimes|array|max:20', 'accessories.*' => 'required|integer|distinct|min:1',
            'branch_id' => [Branches::enabled() ? 'required' : 'sometimes', 'integer', Rule::in([Branches::id()])],
        ]);
        $data = [
            'variant_id' => (int) $data['variant_id'], 'quantity' => (int) $data['quantity'],
            'message' => $data['message'] ?? null, 'note' => $data['note'] ?? null,
            'accessories' => array_map('intval', $data['accessories'] ?? []),
        ];

        if ($line !== null) {
            $originalProduct = DB::table('variants')->where('id', $cart[$line]['variant_id'])->value('product_id');
            $newProduct = DB::table('variants')->where('id', $data['variant_id'])->value('product_id');
            abort_unless($originalProduct === $newProduct, 422);
        }

        $key = $line ?? (string) Str::uuid();
        $cart[$key] = $data;
        if (count($cart) > 30) {
            throw ValidationException::withMessages(['quantity' => 'Giỏ hàng chỉ hỗ trợ tối đa 30 dòng bánh.']);
        }
        $updated = collect($checkout->cart($cart))->firstWhere('key', $key);
        if (! $updated['accessories_available']) {
            throw ValidationException::withMessages(['accessories' => 'Có phụ kiện chưa được duyệt, ngừng cung cấp hoặc không thuộc bánh này. Vui lòng chọn lại.']);
        }
        if (! $updated['available']) {
            throw ValidationException::withMessages(['quantity' => 'Bánh đã hết hoặc không đủ số lượng cho giỏ của bạn.']);
        }
        if ($data['message'] !== null && ! $updated['customizable']) {
            throw ValidationException::withMessages(['message' => 'Bánh này không hỗ trợ ghi lời chúc.']);
        }

        $request->session()->put(['cart' => $cart, 'cart_branch_id' => Branches::id(), 'checkout_key' => (string) Str::uuid()]);

        return redirect($line ? '/gio-hang' : '/banh/'.$updated['slug'])
            ->with('notice', $line ? 'Đã cập nhật giỏ hàng.' : 'Đã thêm bánh vào giỏ.');
    }

    public function removeLine(Request $request, string $line)
    {
        $cart = $request->session()->get('cart', []);
        unset($cart[$line]);
        $request->session()->put(['cart' => $cart, 'checkout_key' => (string) Str::uuid()]);

        return redirect('/gio-hang')->with('notice', 'Đã bỏ bánh khỏi giỏ.');
    }

    public function checkout(Request $request, Checkout $checkout)
    {
        $this->guardCartBranch($request);
        $settings = Store::settings();
        if (! $settings['accepting_orders']) {
            return redirect('/gio-hang');
        }

        $lines = $checkout->cart($request->session()->get('cart', []));
        if (! $lines || collect($lines)->contains('available', false)) {
            return redirect('/gio-hang')->with('notice', 'Vui lòng kiểm tra giỏ hàng trước khi đặt.');
        }

        $key = $request->session()->get('checkout_key') ?? (string) Str::uuid();
        $request->session()->put('checkout_key', $key);

        return Inertia::render('Checkout', [
            'lines' => $lines, 'slots' => $checkout->availableSlots($settings, max(array_column($lines, 'prep_minutes'))),
            'regions' => $settings['regions'], 'bankEnabled' => Store::bank($settings) !== null, 'keyToken' => $key,
            'policy' => $settings['order_policy'],
            'privacy' => $settings['privacy_notice'],
            'fulfillments' => $settings['fulfillments'] ?? ['pickup', 'delivery'],
            'paymentMethods' => $settings['payment_methods'] ?? ['cod', 'bank'],
        ]);
    }

    public function quote(Request $request, Checkout $checkout)
    {
        $this->guardCartBranch($request);
        $data = $request->validate($this->quoteRules());
        $lines = $checkout->cart($request->session()->get('cart', []));
        $quote = $checkout->quote($lines, $data, Store::settings());
        $key = $request->session()->get('checkout_key');
        if (! is_string($key)) {
            throw ValidationException::withMessages(['cart' => 'Vui lòng mở lại trang đặt bánh từ giỏ hàng.']);
        }

        return response()->json([
            ...$quote,
            'line_totals' => array_column($lines, 'subtotal', 'key'),
            'slots' => $lines && ! collect($lines)->contains('available', false)
                ? $checkout->availableSlots(Store::settings(), max(array_column($lines, 'prep_minutes'))) : [],
            'quote_token' => $checkout->quoteToken($quote, $lines, $key, $data),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function place(Request $request, Checkout $checkout)
    {
        try {
            $this->guardCartBranch($request);
            $fields = $request->validate([
                ...$this->quoteRules(), 'key' => 'required|uuid', 'name' => 'required|string|max:120',
                'email' => 'required|email|max:254', 'phone' => ['required', 'string', 'regex:/^(?:\+84|0)[0-9]{9,10}$/'],
                'address' => 'nullable|required_if:fulfillment,delivery|string|max:250',
                'slot' => 'required|date_format:Y-m-d H:i', 'method' => 'required|in:cod,bank',
                'quote_token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            ], [
                'quote_token.required' => 'Hãy tải lại báo giá trước khi đặt bánh.',
                'quote_token.regex' => 'Hãy tải lại báo giá trước khi đặt bánh.',
            ]);
            $key = $request->session()->get('checkout_key');
            if (! $key || ! hash_equals($key, $fields['key'])) {
                throw ValidationException::withMessages(['cart' => 'Giỏ hàng đã thay đổi. Vui lòng mở lại trang đặt bánh.']);
            }
            [$order] = $checkout->place($request->session()->get('cart', []), $fields, $request->user()?->id);
        } catch (ValidationException $failure) {
            throw $failure->redirectTo('/dat-banh');
        }
        $request->session()->forget(['cart', 'cart_branch_id']);

        return redirect(Orders::link($order));
    }

    public function policy()
    {
        $settings = Store::settings();

        return Inertia::render('OrderPolicy', ['policy' => $settings['order_policy'], 'privacy' => $settings['privacy_notice'], 'seo' => PublicSeo::policy()]);
    }

    public function tracking()
    {
        return Inertia::render('Tracking');
    }

    public function track(Request $request)
    {
        $data = $request->validate(['link' => ['required', 'string', 'max:400', 'regex:~(?:^|/)([a-f0-9]{64})/?$~']]);
        preg_match('~([a-f0-9]{64})/?$~', $data['link'], $matches);

        return redirect()->route('tracking.show', ['token' => $matches[1]]);
    }

    public function resendTracking(Request $request)
    {
        $fields = $request->validate([
            'code' => 'required|string|max:22|regex:/^[a-zA-Z0-9-]+$/',
            'email' => 'required|email|max:254',
        ]);
        $order = DB::table('orders')->where('code', Str::upper($fields['code']))->first(['id', 'email']);
        if ($order && hash_equals(Str::lower(trim($order->email)), Str::lower($fields['email']))) {
            try {
                Bus::dispatch(new SendOrderEmail($order->id));
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return redirect('/tra-cuu')->with('notice', 'Nếu mã đơn và email trùng khớp, tiệm sẽ gửi liên kết tra cứu đến email đã đặt đơn.');
    }

    public function tracked(string $token)
    {
        $order = DB::table('orders')->where('tracking_token', $token)->first();
        abort_unless($order, 404);

        return $this->orderResponse($order);
    }

    public function history(Request $request)
    {
        $orders = DB::table('orders')->where('user_id', $request->user()->id)->latest('id')
            ->paginate(12, ['code', 'status', 'payment', 'total', 'created_at']);

        return Inertia::render('History', ['orders' => $orders, 'statuses' => Orders::STATUSES])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function ownOrder(Request $request, string $code)
    {
        return $this->orderResponse($this->ownedOrder($request, $code), canReorder: true);
    }

    public function reorder(Request $request, string $code, Checkout $checkout)
    {
        $request->validate(['branch_id' => [Branches::enabled() ? 'required' : 'sometimes', 'integer', Rule::in([Branches::id()])]]);
        $this->guardCartBranch($request);
        $this->guardCartBranch($request);
        $order = $this->ownedOrder($request, $code);
        $items = DB::table('order_items')->where('order_id', $order->id)->orderBy('id')->get(['variant_id', 'quantity']);
        [$cart, $added, $skipped] = $checkout->reorder($request->session()->get('cart', []), $items);
        if ($added === 0) {
            return back()->with('notice', 'Giỏ đã đầy hoặc các bánh trong đơn này hiện không còn đủ tồn để thêm.');
        }

        $request->session()->put(['cart' => $cart, 'cart_branch_id' => Branches::id(), 'checkout_key' => (string) Str::uuid()]);
        $notice = "Đã thêm {$added} bánh vào giỏ theo giá hiện tại của cơ sở đang chọn.";
        if ($skipped > 0) {
            $notice .= " Còn {$skipped} bánh chưa thể thêm do tồn kho, trạng thái bán hoặc giới hạn giỏ hàng.";
        }

        return redirect('/gio-hang')->with('notice', $notice);
    }

    private function ownedOrder(Request $request, string $code): object
    {
        $order = DB::table('orders')->where('user_id', $request->user()->id)->where('code', $code)->first();
        abort_unless($order, 404);

        return $order;
    }

    private function orderResponse(object $order, bool $canReorder = false)
    {
        $props = Branches::run((int) ($order->branch_id ?? 1), fn () => [
            'order' => Orders::detail($order, owner: $canReorder), 'statuses' => Orders::STATUSES, 'payments' => Orders::PAYMENTS,
            'trackingLink' => Orders::link($order), 'canReorder' => $canReorder,
            ...OrderRequests::customerData($order, $canReorder ? request()->user() : null),
        ]);

        return Inertia::render('Order', $props)->toResponse(request())->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function guardCartBranch(Request $request): void
    {
        if (! Branches::enabled() || ! $request->session()->get('cart')) {
            return;
        }
        // Pre-migration carts belong to the original branch; never silently reprice them at another branch.
        $branchId = (int) $request->session()->get('cart_branch_id', 1);
        if ($branchId !== Branches::id()) {
            throw ValidationException::withMessages(['cart' => 'Giỏ đang thuộc cơ sở khác. Chọn lại cơ sở của giỏ hoặc xác nhận đổi cơ sở để tạo giỏ mới.']);
        }
    }

    private function quoteRules(): array
    {
        return [
            'fulfillment' => ['required', Rule::in(['pickup', 'delivery'])],
            'region' => 'nullable|required_if:fulfillment,delivery|string|max:60',
            'coupon' => 'nullable|string|max:32',
            'branch_id' => [Branches::enabled() ? 'required' : 'sometimes', 'integer', Rule::in([Branches::id()])],
            'method' => 'sometimes|required|in:cod,bank',
            'slot' => 'nullable|date_format:Y-m-d H:i',
        ];
    }
}
