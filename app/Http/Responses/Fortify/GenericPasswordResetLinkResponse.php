<?php

namespace App\Http\Responses\Fortify;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;

class GenericPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse
{
    public function __construct(private readonly string $status) {}

    public function toResponse($request)
    {
        $message = trans($this->status);

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message], 200)
            : back()
                ->withInput($request->only('email'))
                ->with('status', $message);
    }
}
