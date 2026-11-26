<?php

namespace App\Http\Controllers;

use App\Jobs\SendOrderEmail;
use App\Services\Branches;
use App\Services\Checkout;
use App\Services\OrderRequests;
use App\Services\Orders;
use App\Services\Store;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminOrders extends Controller
{
    public function dashboard(Request $request)
    {
        Branches::authorize($request->user(), 'view_orders');
        $chain = $this->chain($request);
        $filters = $this->filters($request);
        $orders = $this->dateRange($this->orderQuery($chain), $filters, 'created_at');
        $revenue = $this->dateRange($this->completedSales($chain), $filters, 'completed_at');
        $low = $this->lowStock($chain);

        return Inertia::render('AdminDashboard', [
            'filters' => $filters, 'total' => (clone $orders)->count(), 'revenue' => (int) $revenue->sum('total'),
            'working' => (clone $orders)->whereIn('status', ['pending', 'confirmed', 'preparing', 'ready', 'shipping', 'delivery_failed', 'uncollected'])->count(),
            'lowCount' => (clone $low)->count(),
            'low' => $low->orderBy('variants.stock')->limit(25)->get(['products.id as product_id', 'products.name as product_name', 'variants.name', 'variants.stock']),
            'recent' => $orders->latest('id')->limit(6)->get(['id', 'code', 'name', 'status', 'payment', 'total', ...(Branches::enabled() ? ['branch_id'] : [])]),
            'statuses' => Orders::STATUSES,
            'scope' => $chain ? 'chain' : 'branch',
        ]);
    }

    public function index(Request $request)
    {
        Branches::authorize($request->user(), 'view_orders');
        abort_if($request->input('scope') === 'chain', 403);
        $filters = $this->filters($request);

        return Inertia::render('AdminOrders', [
            'orders' => $this->filtered($filters)->paginate(15, ['id', 'code', 'name', 'status', 'payment', 'method', 'total', 'created_at'])->withQueryString(),
            'filters' => $filters, 'statuses' => Orders::STATUSES, 'payments' => Orders::PAYMENTS,
        ]);
    }

    public function inbox(Request $request)
    {
        Branches::authorize($request->user(), 'view_orders');
        $fields = $request->validate(['filter' => 'nullable|in:new,overdue,refund,requests,exception,inventory']);
        $filter = $fields['filter'] ?? 'new';
        $new = Orders::query()->where('status', 'pending');
        $overdue = $this->unpaidTransfers()->where('bank_due_at', '<=', now()->toDateTimeString());
        $refund = Orders::query()->where('status', 'cancelled')->where('payment', 'paid');
        $requests = Orders::query()->whereExists(fn ($q) => $q->selectRaw('1')->from('order_change_requests')
            ->whereColumn('order_change_requests.order_id', 'orders.id')->where('order_change_requests.status', 'pending'));
        $exceptions = Orders::query()->whereIn('status', ['delivery_failed', 'uncollected']);
        $inventory = Orders::query()->where('status', 'cancelled')->where('inventory_review_required', true)->whereNull('inventory_processed_at');
        $orders = match ($filter) {
            'overdue' => (clone $overdue)->orderBy('bank_due_at'),
            'refund' => (clone $refund)->orderBy('updated_at'),
            'requests' => (clone $requests)->orderBy('updated_at'),
            'exception' => (clone $exceptions)->orderBy('updated_at'),
            'inventory' => (clone $inventory)->orderBy('updated_at'),
            default => (clone $new)->orderBy('scheduled_at'),
        };
        $settings = Store::settings();

        return Inertia::render('AdminInbox', [
            'filter' => $filter, 'newCount' => $new->count(), 'overdueCount' => $overdue->count(),
            'refundCount' => $refund->count(),
            'requestCount' => $requests->count(), 'exceptionCount' => $exceptions->count(), 'inventoryCount' => $inventory->count(),
            'orders' => $orders->orderBy('id')->paginate(15, ['id', 'code', 'status', 'method', 'payment', 'fulfillment', 'total', 'created_at', 'scheduled_at', 'bank_due_at', 'updated_at'])->withQueryString(),
            'payments' => Orders::PAYMENTS,
            'statuses' => Orders::STATUSES,
            'intake' => ['accepting_orders' => $settings['accepting_orders'], 'pause_message' => $settings['pause_message']],
        ])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function mail(Request $request)
    {
        Branches::authorize($request->user(), 'chain');
        $connection = SendOrderEmail::CONNECTION;
        $queue = config("queue.connections.{$connection}.queue");
        $jobs = DB::table(config("queue.connections.{$connection}.table"))->where('queue', $queue);
        $threshold = (int) config('bakery.mail_wait_minutes');
        $failures = DB::connection(config('queue.failed.database'))->table(config('queue.failed.table'))
            ->where('connection', $connection)->where('queue', $queue);

        return Inertia::render('AdminMail', [
            'queued' => $jobs->count(),
            'delayed' => (clone $jobs)->where('created_at', '<=', now()->subMinutes($threshold)->timestamp)->count(),
            'threshold' => $threshold,
            'failures' => $failures->orderByDesc('failed_at')->orderByDesc('id')->paginate(15, ['uuid', 'failed_at']),
        ])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function reports(Request $request)
    {
        Branches::authorize($request->user(), 'reports');
        $chain = $this->chain($request);
        $filters = $request->validate($this->dateRules($request));
        if (empty($filters['from']) && empty($filters['to'])) {
            $today = now();
            $filters = ['from' => $today->copy()->startOfMonth()->toDateString(), 'to' => $today->toDateString()];
        }
        $orders = $this->dateRange($this->completedSales($chain)->whereNotNull('completed_at'), $filters, 'orders.completed_at');
        $report = DB::transaction(function () use ($orders) {
            $summary = (clone $orders)->selectRaw('COUNT(*) AS order_count, COALESCE(SUM(total), 0) AS revenue')->first();
            $days = (clone $orders)->selectRaw('DATE(completed_at) AS day, COUNT(*) AS order_count, SUM(total) AS revenue')
                ->groupByRaw('DATE(completed_at)')->orderByDesc('day')->paginate(31)->withQueryString();
            $top = (clone $orders)->join('order_items as items', 'items.order_id', '=', 'orders.id')
                ->join('variants', 'variants.id', '=', 'items.variant_id')
                ->join('products', 'products.id', '=', 'variants.product_id')
                ->groupBy('products.id', 'products.name')->orderByDesc('quantity')->orderBy('products.id')
                ->limit(10)->selectRaw('products.id, products.name, SUM(items.quantity) AS quantity, COUNT(DISTINCT orders.id) AS order_count')->get();

            return [
                'totals' => ['order_count' => (int) $summary->order_count, 'revenue' => (int) $summary->revenue],
                'days' => $days->through(fn ($day) => [...(array) $day, 'revenue' => (int) $day->revenue]),
                'top' => $top->map(fn ($product) => [...(array) $product, 'quantity' => (int) $product->quantity]),
            ];
        });

        return Inertia::render('AdminReports', ['filters' => $filters, 'scope' => $chain ? 'chain' : 'branch', ...$report])
            ->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function reconciliation(Request $request)
    {
        Branches::authorize($request->user(), Branches::enabled() ? 'reconcile' : 'view_orders');
        $fields = $request->validate(['filter' => 'nullable|in:pending,overdue']);
        $filter = $fields['filter'] ?? 'pending';
        $now = now()->toDateTimeString();
        $pending = $this->unpaidTransfers();

        $orders = $pending->when($filter === 'overdue', fn ($query) => $query->where('bank_due_at', '<=', $now))
            ->orderBy('bank_due_at')->orderBy('id')
            ->paginate(15, ['id', 'code', 'total', 'created_at', 'scheduled_at', 'bank_due_at'])->withQueryString()
            ->through(function ($order) use ($now) {
                $order->overdue = $order->bank_due_at !== null && $order->bank_due_at <= $now;

                return $order;
            });

        return Inertia::render('AdminReconciliation', ['orders' => $orders, 'filter' => $filter])
            ->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    private function unpaidTransfers(): Builder
    {
        return Orders::scheduled()->where('method', 'bank')->where('payment', 'unpaid');
    }

    public function show(Request $request, int $order, Checkout $checkout)
    {
        Branches::authorize($request->user(), 'view_orders');
        $row = Orders::query()->find($order);
        abort_unless($row, 404);

        $manager = Branches::isManager($request->user());
        $canProcess = Branches::can($request->user(), 'orders');
        $settings = Store::settings();
        $detail = Orders::detail($row, internal: true);
        if (! Orders::canReconcile($request->user())) {
            unset($detail['refund_evidence']);
        }
        if (Branches::enabled() && Branches::role($request->user()) === 'accountant') {
            foreach (['email', 'phone', 'address', 'note', 'inventory_dispositions'] as $private) {
                unset($detail[$private]);
            }
            $detail['items'] = $detail['items']->map(function ($item) {
                unset($item->message, $item->note);

                return $item;
            });
            $detail['events'] = $detail['events']->map(function ($event) {
                unset($event->note);

                return $event;
            });
        }

        return Inertia::render('AdminOrder', [
            'order' => $detail, 'statuses' => Orders::STATUSES, 'payments' => Orders::PAYMENTS,
            'next' => $canProcess ? array_values(array_filter(Orders::next($row), fn ($next) => $next !== 'cancelled' || $manager || Orders::canCustomerRequest($row))) : [],
            'reconcile' => Orders::canReconcile($request->user()),
            'manager' => $manager, 'prepayRequired' => Orders::prepayRequired($row, $settings),
            'prepayExceptionAllowed' => (bool) $settings['allow_payment_override'],
            'changeRequests' => $canProcess ? OrderRequests::history($order, internal: true) : [],
            'rescheduleSlots' => $canProcess && Orders::canReschedule($row, $manager) && $row->prep_minutes_snapshot !== null && ($manager || Orders::canCustomerRequest($row))
                ? array_values(array_diff($checkout->availableSlots($settings, Orders::prepMinutes($order)), [substr($row->scheduled_at, 0, 16)]))
                : null,
        ])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function change(Request $request, int $order)
    {
        $url = '/quan-tri/don-hang/'.$order;
        try {
            $fields = $request->validate([
                'kind' => 'required|in:status,payment,lead_time,prepay_exception', 'value' => 'required|string|max:16', 'note' => 'nullable|string|max:250',
                'expected_status' => ['sometimes', Rule::in(array_keys(Orders::STATUSES))],
                'refund_method' => 'nullable|in:bank,cash', 'refund_reference' => 'nullable|string|max:160',
                'refund_amount' => 'nullable|integer|min:0', 'refund_verified' => 'nullable|boolean',
            ]);
            Orders::change($order, $request->user(), $fields);
        } catch (ValidationException $failure) {
            throw $failure->redirectTo($url);
        }

        return redirect($url)->with('notice', 'Đã cập nhật đơn.');
    }

    public function disposeInventory(Request $request, int $order)
    {
        Branches::authorize($request->user(), 'inventory');
        abort_unless(Branches::isManager($request->user()), 403);
        $fields = $request->validate([
            'items' => 'required|array|min:1|max:100', 'items.*' => 'required|array:item_id,unmade,sellable,waste',
            'items.*.item_id' => 'required|integer|distinct|min:1',
            'items.*.unmade' => 'required|integer|min:0|max:65535',
            'items.*.sellable' => 'required|integer|min:0|max:65535',
            'items.*.waste' => 'required|integer|min:0|max:65535', 'note' => 'required|string|max:250',
        ]);
        Orders::disposeInventory($order, $request->user(), $fields);

        return redirect('/quan-tri/don-hang/'.$order)->with('notice', 'Đã chốt kiểm kê bánh của đơn hủy.');
    }

    public function reschedule(Request $request, int $order, Checkout $checkout)
    {
        Branches::authorize($request->user(), 'orders');
        $url = '/quan-tri/don-hang/'.$order;
        try {
            $fields = $request->validate([
                'slot' => 'required|date_format:Y-m-d H:i', 'previous_slot' => 'required|date_format:Y-m-d H:i',
                'note' => 'required|string|max:200',
            ]);
            Orders::reschedule($order, $request->user(), $fields, $checkout);
        } catch (ValidationException $failure) {
            throw $failure->redirectTo($url);
        }

        return redirect($url)->with('notice', 'Đã lưu giờ giao hoặc nhận mới.');
    }

    public function export(Request $request)
    {
        Branches::authorize($request->user(), 'export');
        $query = $this->filtered($this->filters($request), $this->chain($request));
        $branchCodes = Branches::enabled()
            ? DB::table('branches')->whereIn('id', Branches::allowedIds($request->user()))->pluck('code', 'id')->all()
            : null;

        return response()->streamDownload(function () use ($query, $branchCodes) {
            $file = fopen('php://output', 'wb');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['Mã đơn', 'Ngày đặt', 'Khách hàng', 'Trạng thái', 'Thanh toán', 'Tổng VND', ...($branchCodes === null ? [] : ['Cơ sở'])], escape: '');
            $query->reorder('id', 'desc')->chunkByIdDesc(250, function ($orders) use ($file, $branchCodes) {
                foreach ($orders as $order) {
                    $name = preg_match('/^(?:\s*[=+@-]|[\t\r\n])/', $order->name) ? "'".$order->name : $order->name;
                    $location = $branchCodes === null ? [] : [($order->branch_id ?? 1).' · '.($branchCodes[$order->branch_id ?? 1] ?? '')];
                    fputcsv($file, [$order->code, $order->created_at, $name, Orders::STATUSES[$order->status], Orders::PAYMENTS[$order->payment], $order->total, ...$location], escape: '');
                }
            });
            fclose($file);
        }, 'don-banh-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            ...$this->dateRules($request),
            'code' => ['nullable', 'string', 'max:22', 'regex:/^[A-Za-z0-9-]+$/'],
            'status' => ['nullable', Rule::in(array_keys(Orders::STATUSES))],
        ]);
    }

    private function dateRules(Request $request): array
    {
        return [
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d'.($request->filled('from') ? '|after_or_equal:from' : ''),
        ];
    }

    private function chain(Request $request): bool
    {
        $fields = $request->validate(['scope' => 'nullable|in:branch,chain']);
        if (($fields['scope'] ?? 'branch') !== 'chain') {
            return false;
        }
        Branches::authorize($request->user(), 'chain');

        return Branches::enabled();
    }

    private function orderQuery(bool $chain = false): Builder
    {
        return $chain
            ? DB::table('orders')->whereIn('orders.branch_id', Branches::allowedIds(request()->user()))
            : Orders::query();
    }

    private function lowStock(bool $chain): Builder
    {
        if (! Branches::enabled()) {
            return DB::table('variants')->join('products', 'products.id', '=', 'variants.product_id')
                ->where('products.active', true)->where('variants.active', true)->where('variants.stock', '<=', 5);
        }

        return DB::table('branch_variants as variants')
            ->join('variants as menu_variants', 'menu_variants.id', '=', 'variants.variant_id')
            ->join('products', 'products.id', '=', 'menu_variants.product_id')
            ->where('products.active', true)->where('menu_variants.active', true)->where('variants.active', true)
            ->whereIn('variants.branch_id', $chain ? Branches::allowedIds(request()->user()) : [Branches::id()])
            ->where('variants.stock', '<=', 5)->select('products.id as product_id', 'products.name as product_name', 'menu_variants.name', 'variants.stock', 'variants.branch_id');
    }

    private function completedSales(bool $chain = false): Builder
    {
        return $this->orderQuery($chain)->where('orders.status', 'completed')->where('orders.payment', 'paid');
    }

    private function filtered(array $filters, bool $chain = false): Builder
    {
        return $this->dateRange($this->orderQuery($chain), $filters, 'created_at')
            ->when($filters['code'] ?? null, fn ($query, $code) => $query->where('code', 'like', strtoupper($code).'%'))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('id');
    }

    private function dateRange(Builder $query, array $filters, string $column): Builder
    {
        return $query
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->where($column, '>=', $date.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->where($column, '<', CarbonImmutable::parse($date)->addDay()->toDateTimeString()));
    }
}
