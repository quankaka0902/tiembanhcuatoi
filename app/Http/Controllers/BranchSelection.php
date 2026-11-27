<?php

namespace App\Http\Controllers;

use App\Services\Branches;
use App\Services\BranchSelection as Selection;
use App\Services\DeliveryAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BranchSelection extends Controller
{
    public function preview(Request $request)
    {
        try {
            $fields = $request->validate(['target_branch_id' => 'required|integer|min:1', 'fulfillment' => 'required|in:pickup,delivery',
                'method' => 'required|in:cod,bank', 'area_code' => 'nullable|string|max:80', 'country_code' => 'nullable|in:VN',
                'address_line' => 'nullable|string|max:250', 'delivery_note' => 'nullable|string|max:500', 'pin' => 'nullable|array',
                'slot' => 'nullable|date_format:Y-m-d H:i', 'desired_slot' => 'nullable|date_format:Y-m-d H:i']);
            if (isset($fields['pin'])) {
                $fields['pin'] = DeliveryAddress::pin($fields['pin']);
            }

            return response()->json(Selection::preview($request->session()->get('cart', []), $fields, $this->cartBranch($request)))->header('Cache-Control', 'private, no-store');
        } catch (ValidationException $failure) {
            return response()->json(['message' => 'Kiểm tra cơ sở và lựa chọn nhận.', 'errors' => $failure->errors()], 422);
        }
    }

    public function confirm(Request $request)
    {
        $fields = $request->validate(['branch_id' => 'required|integer|min:1', 'quote_token' => 'required|string|max:30000', 'confirm' => 'required|accepted', 'mode' => 'required|in:keep,clear']);
        $result = Selection::confirm($request->session()->get('cart', []), $fields, $this->cartBranch($request));
        if ($fields['mode'] === 'clear') {
            $request->session()->forget(['cart', 'cart_branch_id']);
        } else {
            $request->session()->put('cart_branch_id', (int) $fields['branch_id']);
        }
        // Explicit selection consent permits a short-lived encrypted fulfillment draft only.
        $request->session()->put(['branch_id' => (int) $fields['branch_id'], 'checkout_key' => (string) Str::uuid(),
            'delivery_draft' => ['expires' => now()->addMinutes(30)->timestamp, 'branch_id' => (int) $fields['branch_id'],
                'encrypted' => Crypt::encryptString(json_encode($result['draft'], JSON_THROW_ON_ERROR))]]);
        Branches::use((int) $fields['branch_id']);

        return redirect($fields['mode'] === 'keep' && $request->session()->get('cart') ? '/dat-banh' : '/thuc-don')
            ->with('notice', 'Đã chọn cơ sở. Giá, lịch và phí sẽ được kiểm tra lại khi đặt.');
    }

    private function cartBranch(Request $request): int
    {
        return $request->session()->get('cart') ? (int) $request->session()->get('cart_branch_id', 1) : Branches::id();
    }
}
