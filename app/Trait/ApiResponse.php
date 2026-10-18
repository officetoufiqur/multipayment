<?php

namespace App\Trait;

trait ApiResponse
{
    public function successResponse($data, $message = null, $code = 200, $customKey = 'data')
    {
        return response()->json([
            'success' => true,
            $customKey => $data,
            'message' => $message,
        ], $code);
    }

    public function errorResponse($message, $code)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $code);
    }
}
