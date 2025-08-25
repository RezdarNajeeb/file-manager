<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DebugController extends Controller
{
    public function testTus(Request $request)
    {
        return response()->json([
            'message' => 'TUS endpoint is accessible',
            'method' => $request->method(),
            'headers' => $request->headers->all(),
            'path' => $request->path(),
            'url' => $request->url(),
        ]);
    }
}
