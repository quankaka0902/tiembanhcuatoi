<?php

namespace App\Http\Controllers;

use App\Jobs\SendOrderEmail;
use App\Services\Catalog;
use App\Services\Checkout;
use App\Services\OrderRequests;
use App\Services\Orders;
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
    public function home()
    {
        return Inertia::render('Home', [
            'categories' => DB::table('categories')->orderBy('id')->get(),
            'featured' => Catalog::listings()->where('products.featured', true)->orderBy('products.id')->limit(4)->get()->map(Catalog::images(...)),
        ]);
    }

    public function catalog(Request $request)
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:100', 'category' => 'nullable|string|max:120',
            'min' => 'nullable|integer|min:0|max:100000000',
            'max' => 'nullable|integer|min:0|max:100000000'.($request->filled('min') ? '|gte:min' : ''),
            'sort' => 'nullable|in:featured,new,price_asc,price_desc', 'page' => 'nullable|integer|min:1',
        ]);
        $query = Catalog::listings();
        if (! empty($filters['q'])) {
            $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $query->where(fn ($q) => $q->whereRaw("products.name LIKE ? ESCAPE '!'", [$search])
                ->orWhereRaw("products.description LIKE ? ESCAPE '!'", [$search]));
        }
        $query->when($filters['category'] ?? null, fn ($q, $category) => $q->where('categories.slug', $category));
        if (isset($filters['min'])) {
            $query->where('availability.price', '>=', (int) $filters['min']);
        }
        if (isset($filters['max'])) {
            $query->where('availability.price', '<=', (int) $filters['max']);
        }
        match ($filters['sort'] ?? 'featured') {
            'price_asc' => $query->orderByRaw('availability.price IS NULL')->orderBy('availability.price'),
            'price_desc' => $query->orderByDesc('availability.price'),
            'new' => $query->orderByDesc('products.created_at'),
            default => $query->orderByDesc('products.featured'),
        };

        return Inertia::render('Catalog', [
            'products' => $query->orderBy('products.id')->paginate(8)->withQueryString()->through(Catalog::images(...)),
            'categories' => DB::table('categories')->orderBy('id')->get(), 'filters' => $filters,
        ]);
    }

    public function product(string $slug)
    {
        $product = DB::table('products')->join('categories', 'categories.id', '=', 'products.category_id')
            ->where('products.slug', $slug)->where('products.active', true)
            ->first(['products.*', 'categories.name as category']);
        abort_unless($product, 404);
        $product = Catalog::images($product);
        $product->variants = DB::table('variants')->where('product_id', $product->id)->where('active', true)
            ->orderBy('id')->get(['id', 'name', 'price', 'stock']);

        return Inertia::render('Product', ['product' => $product]);
    }

    public function cart(Request $request, Checkout $checkout)
    {
        return Inertia::render('Cart', ['lines' => $checkout->cart($request->session()->get('cart', []))]);
    }

    public function saveLine(Request $request, Checkout $checkout, ?string $line = null)
    {
        $cart = $request->session()->get('cart', []);
        abort_if($line !== null && ! isset($cart[$line]), 404);
        $data = $request->validate([
            'variant_id' => 'required|integer|exists:variants,id', 'quantity' => 'required|integer|min:1|max:30',
            'message' => 'nullable|string|max:60', 'note' => 'nullable|string|max:250',
        ]);
        $data = [
            'variant_id' => (int) $data['variant_id'], 'quantity' => (int) $data['quantity'],
            'message' => $data['message'] ?? null, 'note' => $data['note'] ?? null,
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
        if (! $updated['available']) {
            throw ValidationException::withMessages(['quantity' => 'Bánh đã hết hoặc không đủ số lượng cho giỏ của bạn.']);
        }
        if ($data['message'] !== null && ! $updated['customizable']) {
            throw ValidationException::withMessages(['message' => 'Bánh này không hỗ trợ ghi lời chúc.']);
        }

        $request->session()->put(['cart' => $cart, 'checkout_key' => (string) Str::uuid()]);

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
        ]);
    }

    public function quote(Request $request, Checkout $checkout)
    {
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
            'quote_token' => $checkout->quoteToken($quote, $lines, $key),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function place(Request $request, Checkout $checkout)
    {
        try {
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
        $request->session()->forget('cart');

        return redirect(Orders::link($order));
    }

    public function policy()
    {
        $settings = Store::settings();

        return Inertia::render('OrderPolicy', ['policy' => $settings['order_policy'], 'privacy' => $settings['privacy_notice']]);
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
        $order = Orders::query()->where('code', Str::upper($fields['code']))->first(['id', 'email']);
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
        $order = Orders::query()->where('tracking_token', $token)->first();
        abort_unless($order, 404);

        return $this->orderResponse($order);
    }

    public function history(Request $request)
    {
        $orders = Orders::query()->where('user_id', $request->user()->id)->latest('id')
            ->paginate(12, ['code', 'status', 'payment', 'total', 'created_at']);

        return Inertia::render('History', ['orders' => $orders, 'statuses' => Orders::STATUSES])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function ownOrder(Request $request, string $code)
    {
        return $this->orderResponse($this->ownedOrder($request, $code), canReorder: true);
    }

    public function reorder(Request $request, string $code, Checkout $checkout)
    {
        $order = $this->ownedOrder($request, $code);
        $items = DB::table('order_items')->where('order_id', $order->id)->orderBy('id')->get(['variant_id', 'quantity']);
        [$cart, $added, $skipped] = $checkout->reorder($request->session()->get('cart', []), $items);
        if ($added === 0) {
            return back()->with('notice', 'Giỏ đã đầy hoặc các bánh trong đơn này hiện không còn đủ tồn để thêm.');
        }

        $request->session()->put(['cart' => $cart, 'checkout_key' => (string) Str::uuid()]);
        $notice = "Đã thêm {$added} bánh vào giỏ theo giá hiện tại.";
        if ($skipped > 0) {
            $notice .= " Còn {$skipped} bánh chưa thể thêm do tồn kho, trạng thái bán hoặc giới hạn giỏ hàng.";
        }

        return redirect('/gio-hang')->with('notice', $notice);
    }

    private function ownedOrder(Request $request, string $code): object
    {
        $order = Orders::query()->where('user_id', $request->user()->id)->where('code', $code)->first();
        abort_unless($order, 404);

        return $order;
    }

    private function orderResponse(object $order, bool $canReorder = false)
    {
        return Inertia::render('Order', [
            'order' => Orders::detail($order, owner: $canReorder), 'statuses' => Orders::STATUSES, 'payments' => Orders::PAYMENTS,
            'trackingLink' => Orders::link($order), 'canReorder' => $canReorder,
            ...OrderRequests::customerData($order, $canReorder ? request()->user() : null),
        ])->toResponse(request())->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function quoteRules(): array
    {
        return [
            'fulfillment' => ['required', Rule::in(['pickup', 'delivery'])],
            'region' => 'nullable|required_if:fulfillment,delivery|string|max:60',
            'coupon' => 'nullable|string|max:32',
        ];
    }
}
