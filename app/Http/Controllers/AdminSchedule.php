<?php

namespace App\Http\Controllers;

use App\Services\Branches;
use App\Services\Orders;
use App\Services\Store;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AdminSchedule extends Controller
{
    public function __invoke(Request $request)
    {
        Branches::authorize($request->user(), 'production');
        $fields = $request->validate(['date' => 'nullable|date_format:Y-m-d']);
        $today = CarbonImmutable::today();
        $date = isset($fields['date']) ? CarbonImmutable::parse($fields['date']) : $today;
        $settings = Store::settings();
        $orders = Orders::scheduled()->where('scheduled_at', '>=', $date->toDateTimeString())
            ->where('scheduled_at', '<', $date->addDay()->toDateTimeString())
            ->orderBy('scheduled_at')->orderBy('id')->get(['id', 'code', 'scheduled_at', 'fulfillment', 'status']);
        $kitchen = Branches::enabled() && Branches::role($request->user()) === 'kitchen';
        $items = DB::table('order_items')->whereIntegerInRaw('order_id', $orders->pluck('id'))
            ->orderBy('id')->get(['id', 'order_id', 'variant_id', 'product_name', 'variant_name', 'quantity', 'message', ...($kitchen ? [] : ['note'])]);
        $itemsByOrder = $items->groupBy('order_id');
        foreach ($orders as $order) {
            $order->items = $itemsByOrder->get($order->id, []);
            if ($kitchen) {
                $order->next = array_values(array_intersect(Orders::next($order), ['preparing', 'ready']));
            }
        }

        $ordersByTime = $orders->groupBy(fn ($order) => substr($order->scheduled_at, 11, 5));
        $times = collect(Store::openOn($settings, $date) ? $settings['slots'] : [])
            ->merge($ordersByTime->keys())->unique()->sort()->values();
        $schedule = $times->map(function (string $time) use ($settings, $ordersByTime) {
            $orders = $ordersByTime->get($time, collect());
            $products = $orders->flatMap(fn ($order) => $order->items)
                ->groupBy(fn ($item) => json_encode([$item->variant_id, $item->product_name, $item->variant_name], JSON_THROW_ON_ERROR))
                ->map(function ($items) {
                    $item = $items->first();

                    return [
                        'variant_id' => $item->variant_id, 'product_name' => $item->product_name,
                        'variant_name' => $item->variant_name, 'quantity' => $items->sum('quantity'),
                    ];
                })->values();

            return ['time' => $time, 'limit' => $settings['slot_limits'][$time] ?? null, 'orders' => $orders, 'products' => $products];
        });

        return Inertia::render('AdminSchedule', [
            'date' => $date->toDateString(), 'today' => $today->toDateString(), 'tomorrow' => $today->addDay()->toDateString(),
            'orderCount' => $orders->count(), 'cakeCount' => $items->sum('quantity'), 'schedule' => $schedule,
            'statuses' => Orders::STATUSES,
            'productionOnly' => $kitchen,
        ])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }
}
