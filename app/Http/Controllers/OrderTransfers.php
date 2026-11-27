<?php

namespace App\Http\Controllers;

use App\Services\Branches;
use App\Services\OrderTransfers as Transfers;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class OrderTransfers extends Controller
{
    public function preview(Request $request, int $order)
    {
        try {
            $data = Transfers::preview($order, $request->user(), $request->validate($this->quoteRules()));

            return response()->json($data)->header('Cache-Control', 'private, no-store');
        } catch (ValidationException $failure) {
            return response()->json(['message' => 'Chưa thể báo giá chuyển cơ sở.', 'errors' => $failure->errors()], 422);
        }
    }

    public function propose(Request $request, int $order)
    {
        Transfers::propose($order, $request->user(), $request->validate($this->quoteRules() + [
            'quote_token' => 'required|string|max:20000', 'request_key' => 'required|uuid', 'note' => 'required|string|min:3|max:250',
        ]));

        return back()->with('notice', 'Đã gửi báo giá cho khách xác nhận riêng. Đơn vẫn được giữ tại cơ sở cũ.');
    }

    public function execute(Request $request, int $proposal)
    {
        $order = Transfers::execute($proposal, $request->user(), $request->validate([
            'request_key' => 'required|uuid', 'note' => 'required|string|min:3|max:250',
            'bank_checked' => 'sometimes|boolean', 'bank_reference' => 'nullable|string|max:80',
        ]));
        $request->session()->put('admin_branch_id', (int) $order->branch_id);
        Branches::use((int) $order->branch_id);

        return redirect('/quan-tri/don-hang/'.$order->id)->with('notice', 'Đã chuyển cơ sở và cập nhật báo giá, tồn, khung giờ trong cùng giao dịch.');
    }

    public function cancel(Request $request, int $proposal)
    {
        Transfers::cancel($proposal, $request->user(), $request->validate(['request_key' => 'required|uuid', 'note' => 'required|string|min:3|max:250']));

        return back()->with('notice', 'Đã đóng đề nghị; giữ nguyên đơn và tài nguyên nguồn.');
    }

    public function guestForm(string $token)
    {
        return $this->form(Transfers::access(token: $token), '/xac-nhan-chuyen-don/'.$token);
    }

    public function ownerForm(Request $request, string $code)
    {
        return $this->form(Transfers::access(owner: $request->user(), code: $code), '/don-cua-toi/'.$code.'/chuyen-co-so');
    }

    public function guestDecision(Request $request, string $token)
    {
        Transfers::approve($request->validate($this->decisionRules()), token: $token);

        return back()->with('notice', 'Đã ghi nhận lựa chọn. Chuyển cơ sở chỉ hoàn tất khi tiệm thực hiện thành công.');
    }

    public function ownerDecision(Request $request, string $code)
    {
        Transfers::approve($request->validate($this->decisionRules()), owner: $request->user(), code: $code);

        return back()->with('notice', 'Đã ghi nhận lựa chọn của bạn.');
    }

    private function form(array $access, string $url)
    {
        [$order, $proposal] = $access;

        return Inertia::render('TransferApproval', ['order' => ['code' => $order->code], 'proposal' => Transfers::summary($proposal),
            'submit_url' => $url, 'expired' => now()->gte($proposal->approval_expires_at) || now()->gte($proposal->expires_at),
            'approved' => $proposal->status === 'approved'])
            ->toResponse(request())->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function quoteRules(): array
    {
        return ['target_branch_id' => 'required|integer|min:1', 'slot' => 'required|date_format:Y-m-d H:i', 'area_code' => 'nullable|string|max:80'];
    }

    private function decisionRules(): array
    {
        return ['proposal_id' => 'required|integer|min:1', 'decision' => 'required|in:approved,rejected',
            'accept_quote' => 'sometimes|boolean', 'request_key' => 'required|uuid'];
    }
}
