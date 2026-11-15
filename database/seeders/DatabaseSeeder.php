<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Orders;
use App\Services\Store;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || ! config('bakery.demo_seed')) {
            throw new LogicException('Dữ liệu mẫu chỉ dùng với APP_ENV=local/testing và BAKERY_DEMO_SEED=true.');
        }
        if (DB::table('products')->exists() || User::query()->exists()) {
            $this->command?->warn('Cơ sở dữ liệu đã có dữ liệu. Bỏ qua seed mẫu.');

            return;
        }

        DB::transaction(function () {
            $settings = array_replace(config('bakery.defaults'), [
                'phone' => '0900 123 456', 'email' => 'hello@tiembanh.example', 'address' => '24 Nguyễn Thiệp, Quận 1, TP. Hồ Chí Minh',
                'regions' => [['key' => 'quan-1', 'name' => 'Quận 1', 'fee' => 25000], ['key' => 'quan-3', 'name' => 'Quận 3', 'fee' => 35000],
                    ['key' => 'binh-thanh', 'name' => 'Bình Thạnh', 'fee' => 45000]],
                'free_from' => 600000, 'bank_name' => 'Ngân hàng minh họa', 'bank_account' => '0000000000', 'bank_holder' => 'TIEM BANH MAU', 'bank_demo' => true,
            ]);
            DB::table('store_settings')->where('id', 1)->update(['data' => json_encode($settings, JSON_THROW_ON_ERROR)]);

            foreach ([
                ['admin', 'Quản trị viên', 'admin', true],
                ['nhanvien', 'Nhân viên tiệm bánh', 'staff', false],
                ['doisoat', 'Nhân viên đối soát', 'staff', true],
                ['khach', 'Khách mua thử', 'customer', false],
            ] as [$account, $name, $role, $reconcile]) {
                (new User)->forceFill(['name' => $name, 'email' => $account.'@tiembanh.test', 'password' => 'BanhDemo2026!', 'role' => $role, 'can_reconcile' => $reconcile])->save();
            }

            foreach ([
                ['Bánh kem', 'banh-kem', 'cake'], ['Bánh nướng', 'banh-nuong', 'croissant'],
                ['Bánh lạnh', 'banh-lanh', 'cupcake'], ['Bánh quy', 'banh-quy', 'cookie'],
            ] as [$name, $slug, $icon]) {
                $categories[] = DB::table('categories')->insertGetId(compact('name', 'slug', 'icon'));
            }

            foreach ([
                [0, 'Bánh Kem Dâu', 'banh-kem-dau', 'strawberry.webp', 'Cốt vanilla mềm, kem sữa nhẹ và dâu tươi chua ngọt.', 360000, 120, true, 16],
                [1, 'Croissant Bơ Pháp', 'croissant-bo-phap', 'croissant.jpg', 'Lớp vỏ giòn, ruột xốp và thơm mùi bơ.', 45000, 30, false, 35],
                [2, 'Cheesecake Việt Quất', 'cheesecake-viet-quat', 'cheesecake.jpg', 'Kem phô mai béo mịn và xốt việt quất nhà nấu.', 49000, 60, false, 22],
                [0, 'Bánh Kem Chocolate', 'banh-kem-chocolate', 'chocolate.jpg', 'Các lớp cốt cacao và kem chocolate đậm vị.', 390000, 150, true, 5],
                [2, 'Tiramisu Cà Phê', 'tiramisu-ca-phe', 'tiramisu.jpg', 'Bánh mềm thấm cà phê, phủ mascarpone và cacao.', 75000, 60, false, 18],
                [3, 'Cookie Chocolate Chip', 'cookie-chocolate-chip', 'cookies.jpg', 'Cookie bơ với chocolate chip, giòn ở rìa và mềm ở giữa.', 35000, 30, false, 30],
                [2, 'Tiramisu Chocolate', 'tiramisu-chocolate', 'tiramisu.jpg', 'Khay tiramisu chia sẻ với mascarpone và chocolate.', 225000, 90, false, 4],
                [0, 'Bánh Kem Dâu Mini', 'banh-kem-dau-mini', 'strawberry.webp', 'Bánh dâu kem sữa cỡ nhỏ cho một buổi tiệc ấm cúng.', 180000, 90, true, 0],
                [1, 'Hộp Croissant Bơ', 'hop-croissant-bo', 'croissant.jpg', 'Croissant nướng vàng, gói hộp để mang đi hoặc làm quà.', 160000, 45, false, 12],
            ] as $index => [$category, $name, $slug, $image, $description, $price, $prep, $custom, $stock]) {
                $product = DB::table('products')->insertGetId([
                    'name' => $name, 'slug' => $slug, 'category_id' => $categories[$category],
                    'description' => $description, 'ingredients' => match ($category) {
                        0 => 'Bột mì, trứng, đường, kem sữa'.($index === 3 ? ', cacao.' : ', dâu tươi.'),
                        1 => 'Bột mì, bơ, men, đường và trứng.',
                        2 => 'Kem phô mai, trứng, đường và bột mì.'.($index === 2 ? ' Xốt việt quất.' : ' Cà phê và cacao.'),
                        default => 'Bột mì, bơ, đường nâu, trứng và chocolate.',
                    },
                    'allergens' => 'Có gluten, trứng và sữa. Có thể tiếp xúc với hạt trong bếp.',
                    'images' => json_encode(['/images/'.$image], JSON_THROW_ON_ERROR),
                    'prep_minutes' => $prep, 'customizable' => $custom, 'message_fee' => $custom ? 15000 : 0,
                    'featured' => $index < 4, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $base[] = DB::table('variants')->insertGetId([
                    'product_id' => $product, 'name' => $custom ? 'Size 16 cm' : ($index === 8 ? 'Hộp 4 cái' : 'Phần tiêu chuẩn'),
                    'price' => $price, 'stock' => $stock,
                ]);
                DB::table('variants')->insert([
                    'product_id' => $product, 'name' => $custom ? 'Size 20 cm' : ($index === 8 ? 'Hộp 8 cái' : 'Hộp 4 phần'),
                    'price' => $custom ? $price + 130000 : $price * ($index === 8 ? 2 : 4),
                    'stock' => $stock ? max(1, intdiv($stock, 2)) : 0,
                ]);
            }

            DB::table('coupons')->insert([
                ['code' => 'NHAMINH10', 'kind' => 'percent', 'amount' => 10, 'minimum' => 200000, 'usage_limit' => 50, 'ends_on' => now()->addMonths(3)->toDateString()],
                ['code' => 'NGOT30000', 'kind' => 'fixed', 'amount' => 30000, 'minimum' => 300000, 'usage_limit' => 10, 'ends_on' => now()->addMonth()->toDateString()],
            ]);

            $customer = User::where('role', 'customer')->firstOrFail();
            foreach ([
                ['pending', 'bank', 'unpaid'], ['confirmed', 'cod', 'unpaid'], ['preparing', 'cod', 'unpaid'],
                ['ready', 'cod', 'unpaid'], ['shipping', 'cod', 'unpaid'], ['completed', 'cod', 'paid'], ['cancelled', 'cod', 'unpaid'],
            ] as $index => [$status, $method, $payment]) {
                $variant = DB::table('variants')->find($base[$index]);
                $product = DB::table('products')->find($variant->product_id);
                $fee = $status === 'shipping' ? 25000 : 0;
                $date = now()->subDays($index);
                $id = DB::table('orders')->insertGetId([
                    'code' => 'NB-DEMO00'.($index + 1), 'tracking_token' => bin2hex(random_bytes(32)), 'request_key' => bin2hex(random_bytes(32)),
                    'name' => $customer->name, 'email' => $customer->email, 'phone' => '0900123456', 'user_id' => $customer->id,
                    'fulfillment' => $fee ? 'delivery' : 'pickup', 'address' => $fee ? '24 Nguyễn Thiệp' : null,
                    'region_label' => $fee ? 'Quận 1' : null, 'scheduled_at' => $date->copy()->addDay()->setTime(14, 0),
                    'status' => $status, 'method' => $method, 'payment' => $payment,
                    'bank_snapshot' => $method === 'bank' ? json_encode(Store::bank($settings), JSON_THROW_ON_ERROR) : null,
                    'subtotal' => $variant->price, 'delivery_fee' => $fee, 'total' => $variant->price + $fee,
                    'completed_at' => $status === 'completed' ? $date : null, 'paid_at' => $payment === 'paid' ? $date : null,
                    'stock_restored_at' => $status === 'cancelled' ? $date : null,
                    'created_at' => $date, 'updated_at' => $date,
                ]);
                DB::table('order_items')->insert([
                    'order_id' => $id, 'variant_id' => $variant->id, 'product_name' => $product->name,
                    'variant_name' => $variant->name, 'unit_price' => $variant->price, 'quantity' => 1,
                ]);
                if ($status !== 'cancelled') {
                    DB::table('variants')->where('id', $variant->id)->decrement('stock');
                }
                Orders::event($id, null, 'status', $status);
            }
        });
    }
}
