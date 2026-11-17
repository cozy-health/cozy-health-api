<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AccountDeletion;
use App\Services\Apple\AppleTokenService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    use ApiResponse;

    public function destroy(Request $request, AppleTokenService $appleTokens, AccountDeletion $deletion)
    {
        $deletion->delete($request->user(), $appleTokens);

        return $this->success(null, 'Account deleted successfully.');
    }
}
