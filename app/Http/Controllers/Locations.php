<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Branches;
use App\Services\BranchRecommendations;
use App\Services\DeliveryAddress;
use App\Services\PublicSeo;
use App\Services\Store;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class Locations extends Controller
{
    public function index(Request $request, BranchRecommendations $recommendations)
    {
        $cart = $request->session()->get('cart', []);

        return Inertia::render('Locations', [
            ...$this->pageProps($request, $recommendations),
            'locations' => $this->publicLocations($recommendations, $cart),
            'seo' => $this->seo('Chọn cơ sở đặt bánh'),
        ]);
    }

    public function show(Request $request, string $slug, BranchRecommendations $recommendations)
    {
        $locations = $this->publicLocations($recommendations, $request->session()->get('cart', []));
        $location = collect($locations)->firstWhere('slug', $slug);
        abort_unless($location, 404);

        return Inertia::render('Location', [
            ...$this->pageProps($request, $recommendations), 'location' => $location, 'seo' => $this->seo($location['name']),
        ]);
    }

    public function recommend(Request $request, BranchRecommendations $recommendations)
    {
        // Always JSON, including validation failures: never flash a precise address.
        try {
            $result = $recommendations->evaluate($request->session()->get('cart', []), $request->all());
        } catch (ValidationException $failure) {
            return response()->json(['message' => 'Kiểm tra lựa chọn nhận bánh.', 'errors' => $failure->errors()], 422);
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    public function addresses(Request $request, BranchRecommendations $recommendations)
    {
        $owner = $this->owner($request);
        $props = ['addresses' => $this->savedAddresses($owner->id), 'areas' => $recommendations->areas()];

        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json($props)->header('Cache-Control', 'private, no-store')
            : Inertia::render('AddressBook', $props);
    }

    public function saveAddress(Request $request, BranchRecommendations $recommendations, ?int $address = null)
    {
        $this->owner($request);
        try {
            $fields = Validator::make($request->all(), [
                'save_consent' => ['required', 'accepted'], 'label' => 'required|string|max:80', 'address' => 'required|array',
            ])->validate();
            $normalized = DeliveryAddress::normalize($fields['address'], $recommendations->configuredRegions());
            $normalized['label'] = DeliveryAddress::text($fields['label']);
        } catch (ValidationException $failure) {
            return $this->addressFailure($request, $failure->errors());
        }
        try {
            DB::transaction(function () use ($request, $address, $normalized) {
                $owner = $this->owner($request, lock: true);
                $existing = $address ? DB::table('customer_delivery_addresses')->where('user_id', $owner->id)->where('id', $address)->lockForUpdate()->first() : null;
                abort_if($address && ! $existing, 404);
                if (! $existing && DB::table('customer_delivery_addresses')->where('user_id', $owner->id)->count() >= 20) {
                    throw ValidationException::withMessages(['label' => 'Có tối đa 20 địa chỉ đã lưu. Hãy sửa hoặc xóa địa chỉ cũ.']);
                }
                $data = ['encrypted_payload' => DeliveryAddress::seal($normalized), 'consented_at' => now(), 'updated_at' => now()];
                if ($existing) {
                    DB::table('customer_delivery_addresses')->where('id', $existing->id)->where('user_id', $owner->id)->update($data);
                } else {
                    DB::table('customer_delivery_addresses')->insert($data + ['user_id' => $owner->id, 'created_at' => now()]);
                }
            });
        } catch (ValidationException $failure) {
            return $this->addressFailure($request, $failure->errors());
        }

        return $request->header('X-Inertia')
            ? redirect('/tai-khoan/dia-chi')->with('notice', 'Đã lưu địa chỉ theo lựa chọn của bạn.')
            : response()->json(['addresses' => $this->savedAddresses($request->user()->id)], $address ? 200 : 201)->header('Cache-Control', 'private, no-store');
    }

    public function deleteAddress(Request $request, int $address)
    {
        DB::transaction(function () use ($request, $address) {
            $owner = $this->owner($request, lock: true);
            $row = DB::table('customer_delivery_addresses')->where('user_id', $owner->id)->where('id', $address)->lockForUpdate()->first();
            abort_unless($row, 404);
            DB::table('customer_delivery_addresses')->where('id', $row->id)->where('user_id', $owner->id)->delete();
        });

        return $request->header('X-Inertia')
            ? redirect('/tai-khoan/dia-chi')->with('notice', 'Đã xóa địa chỉ đã lưu.')
            : response()->json(['addresses' => $this->savedAddresses($request->user()->id)])->header('Cache-Control', 'private, no-store');
    }

    private function owner(Request $request, bool $lock = false): User
    {
        abort_unless($request->user(), 401);
        $query = User::whereKey($request->user()->id)->where('active', true);
        $owner = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($owner, 403);

        return $owner;
    }

    private function savedAddresses(int $ownerId): array
    {
        return DB::table('customer_delivery_addresses')->where('user_id', $ownerId)->orderByDesc('updated_at')->orderByDesc('id')->get()->map(function ($row) {
            try {
                $address = DeliveryAddress::open($row->encrypted_payload);
                $label = $address['label'] ?? 'Địa chỉ đã lưu';
                unset($address['label']);

                return ['id' => (int) $row->id, 'label' => $label, 'address' => $address, 'updated_at' => $row->updated_at, 'readable' => true];
            } catch (DecryptException) {
                return ['id' => (int) $row->id, 'label' => 'Địa chỉ cần kiểm tra', 'address' => null, 'updated_at' => $row->updated_at, 'readable' => false];
            }
        })->all();
    }

    private function addressFailure(Request $request, array $errors)
    {
        return $request->header('X-Inertia') ? redirect('/tai-khoan/dia-chi')->withErrors($errors)
            : response()->json(['message' => 'Kiểm tra địa chỉ và xác nhận lưu.', 'errors' => $errors], 422)->header('Cache-Control', 'private, no-store');
    }

    private function pageProps(Request $request, BranchRecommendations $recommendations): array
    {
        $cart = $request->session()->get('cart', []);

        return ['areas' => $recommendations->areas(), 'current_branch_id' => Branches::id(),
            'cartSummary' => ['line_count' => count($cart), 'quantity' => (int) collect($cart)->sum('quantity')],
            'recommendationEndpoint' => '/co-so/goi-y', 'savedAddressEndpoint' => $request->user()?->active ? '/tai-khoan/dia-chi' : null];
    }

    private function publicLocations(BranchRecommendations $recommendations, array $cart): array
    {
        $eligible = $cart ? collect($recommendations->evaluate($cart, ['fulfillment' => 'pickup', 'method' => 'cod']))->get('candidates', []) : [];
        $eligible = collect($eligible)->keyBy('branch_id');

        return $recommendations->activeBranches()->map(fn ($branch) => Branches::run((int) $branch->id, function () use ($branch, $eligible) {
            $settings = Store::settings();
            $maps = $settings['maps_url'] ?? null;

            return ['id' => (int) $branch->id, 'code' => $branch->code, 'slug' => $branch->slug, 'name' => $branch->name,
                ...array_intersect_key($settings, array_flip(['address', 'hours', 'phone', 'email'])),
                'maps_url' => PublicSeo::safeMapsUrl(is_string($maps) ? $maps : null), 'coordinates' => BranchRecommendations::coordinates($settings),
                'fulfillments' => $settings['fulfillments'] ?? ['pickup', 'delivery'], 'payment_methods' => $settings['payment_methods'] ?? ['cod', 'bank'],
                'accepting_orders' => (bool) $settings['accepting_orders'], 'earliest_slot' => $eligible->get($branch->id)['earliest_slot'] ?? null,
                'availability_note' => $eligible->has($branch->id) ? 'Giờ dự kiến cho cả giỏ hiện tại; kiểm tra lại trước khi đặt.' : 'Chọn bánh và lựa chọn nhận để kiểm tra khả năng phục vụ cả giỏ.'];
        }))->all();
    }

    private function seo(string $title): array
    {
        return ['title' => $title, 'description' => 'Xem thông tin cơ sở và kiểm tra khả năng phục vụ cả giỏ bánh.',
            'canonical' => null, 'robots' => 'noindex, follow', 'og' => [], 'jsonLd' => [], 'indexable' => false];
    }
}
