<?php

namespace App\Http\Controllers;

use App\Jobs\SendOrderEmail;
use App\Services\Checkout;
use App\Services\Orders;
use App\Services\Store;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminOrders extends Controller
{
    public function dashboard(Request $request)
    {
        $filters = $this->filters($request);
        $orders = $this->dateRange(DB::table('orders'), $filters, 'created_at');
        $revenue = $this->dateRange($this->completedSales(), $filters, 'completed_at');
        $low = DB::table('variants')->join('products', 'products.id', '=', 'variants.product_id')
            ->where('products.active', true)->where('variants.active', true)->where('variants.stock', '<=', 5);

        return Inertia::render('AdminDashboard', [
            'filters' => $filters, 'total' => (clone $orders)->count(), 'revenue' => (int) $revenue->sum('total'),
            'working' => (clone $orders)->whereIn('status', ['pending', 'confirmed', 'preparing', 'ready', 'shipping'])->count(),
            'lowCount' => (clone $low)->count(),
            'low' => $low->orderBy('variants.stock')->limit(25)->get(['products.id as product_id', 'products.name as product_name', 'variants.name', 'variants.stock']),
            'recent' => $orders->latest('id')->limit(6)->get(['id', 'code', 'name', 'status', 'payment', 'total']),
            'statuses' => Orders::STATUSES,
        ]);
    }

    public function index(Request $request)
    {
        $filters = $this->filters($request);

        return Inertia::render('AdminOrders', [
            'orders' => $this->filtered($filters)->paginate(15, ['id', 'code', 'name', 'status', 'payment', 'method', 'total', 'created_at'])->withQueryString(),
            'filters' => $filters, 'statuses' => Orders::STATUSES, 'payments' => Orders::PAYMENTS,
        ]);
    }

    public function inbox(Request $request)
    {
        $fields = $request->validate(['filter' => 'nullable|in:new,overdue,refund']);
        $filter = $fields['filter'] ?? 'new';
        $new = DB::table('orders')->where('status', 'pending');
        $overdue = $this->unpaidTransfers()->where('bank_due_at', '<=', now()->toDateTimeString());
        $refund = DB::table('orders')->where('status', 'cancelled')->where('payment', 'paid');
        $orders = match ($filter) {
            'overdue' => (clone $overdue)->orderBy('bank_due_at'),
            'refund' => (clone $refund)->orderBy('updated_at'),
            default => (clone $new)->orderBy('scheduled_at'),
        };
        $settings = Store::settings();

        return Inertia::render('AdminInbox', [
            'filter' => $filter, 'newCount' => $new->count(), 'overdueCount' => $overdue->count(),
            'refundCount' => $refund->count(),
            'orders' => $orders->orderBy('id')->paginate(15, ['id', 'code', 'method', 'total', 'created_at', 'scheduled_at', 'bank_due_at', 'updated_at'])->withQueryString(),
            'intake' => ['accepting_orders' => $settings['accepting_orders'], 'pause_message' => $settings['pause_message']],
        ])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function mail(Request $request)
    {
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
        $filters = $request->validate($this->dateRules($request));
        if (empty($filters['from']) && empty($filters['to'])) {
            $today = now();
            $filters = ['from' => $today->copy()->startOfMonth()->toDateString(), 'to' => $today->toDateString()];
        }
        $orders = $this->dateRange($this->completedSales()->whereNotNull('completed_at'), $filters, 'orders.completed_at');
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

        return Inertia::render('AdminReports', ['filters' => $filters, ...$report])
            ->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function reconciliation(Request $request)
    {
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
        $row = DB::table('orders')->find($order);
        abort_unless($row, 404);

        return Inertia::render('AdminOrder', [
            'order' => Orders::detail($row, internal: true), 'statuses' => Orders::STATUSES, 'payments' => Orders::PAYMENTS,
            'next' => Orders::next($row), 'reconcile' => Orders::canReconcile($request->user()),
            'rescheduleSlots' => Orders::canReschedule($row)
                ? array_values(array_diff($checkout->availableSlots(Store::settings(), Orders::prepMinutes($order)), [substr($row->scheduled_at, 0, 16)]))
                : null,
        ])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function change(Request $request, int $order)
    {
        $fields = $request->validate([
            'kind' => 'required|in:status,payment', 'value' => 'required|string|max:16', 'note' => 'nullable|string|max:250',
        ]);
        Orders::change($order, $request->user(), $fields);

        return back()->with('notice', 'Đã cập nhật đơn.');
    }

    public function reschedule(Request $request, int $order, Checkout $checkout)
    {
        $fields = $request->validate([
            'slot' => 'required|date_format:Y-m-d H:i', 'previous_slot' => 'required|date_format:Y-m-d H:i',
            'note' => 'required|string|max:200',
        ]);
        Orders::reschedule($order, $request->user(), $fields, $checkout);

        return back()->with('notice', 'Đã lưu giờ giao hoặc nhận mới.');
    }

    public function export(Request $request)
    {
        $query = $this->filtered($this->filters($request));

        return response()->streamDownload(function () use ($query) {
            $file = fopen('php://output', 'wb');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['Mã đơn', 'Ngày đặt', 'Khách hàng', 'Trạng thái', 'Thanh toán', 'Tổng VND'], escape: '');
            $query->reorder('id', 'desc')->chunkByIdDesc(250, function ($orders) use ($file) {
                foreach ($orders as $order) {
                    $name = preg_match('/^(?:\s*[=+@-]|[\t\r\n])/', $order->name) ? "'".$order->name : $order->name;
                    fputcsv($file, [$order->code, $order->created_at, $name, Orders::STATUSES[$order->status], Orders::PAYMENTS[$order->payment], $order->total], escape: '');
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

    private function completedSales(): Builder
    {
        return DB::table('orders')->where('orders.status', 'completed')->where('orders.payment', 'paid');
    }

    private function filtered(array $filters): Builder
    {
        return $this->dateRange(DB::table('orders'), $filters, 'created_at')
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
