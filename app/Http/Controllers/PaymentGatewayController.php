<?php

namespace App\Http\Controllers;

use App\Helpers\FileUpload;
use App\Models\PaymentGetway;
use App\Trait\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PaymentGatewayController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $getways = PaymentGetway::all();

        return $this->successResponse(
            $getways,
            'Getways fetched successfully'
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'base_url' => ['nullable', 'url'],
            'username' => ['nullable', 'string'],
            'password' => ['nullable', 'string'],
            'app_key' => ['nullable', 'string'],
            'app_secret' => ['nullable', 'string'],
            'webhook' => ['nullable', 'string'],
            'store_id' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'is_testmode' => ['boolean'],
            'is_localhost' => ['boolean'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,svg,webp', 'max:2048'],
        ]);

        $logoPath = null;
        if ($request->hasFile('logo')) {
            $logoPath = FileUpload::storeFile($request->file('logo'), 'uploads/getways');
        }

        $gateway = PaymentGetway::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'base_url' => $validated['base_url'] ?? null,
            'username' => $validated['username'] ?? null,
            'password' => $validated['password'] ?? null,
            'app_key' => $validated['app_key'] ?? null,
            'app_secret' => $validated['app_secret'] ?? null,
            'webhook' => $validated['webhook'] ?? null,
            'store_id' => $validated['store_id'] ?? null,
            'is_active' => $request->boolean('is_active', true),
            'is_testmode' => $request->boolean('is_testmode', true),
            'is_localhost' => $request->boolean('is_localhost', false),
            'logo' => $logoPath,
        ]);

        return $this->successResponse(
            'Gateway created successfully',
            $gateway
        );
    }

    public function update(Request $request, $id)
    {
        $getway = PaymentGetway::findOrFail($id);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('payment_getways', 'name')->ignore($getway)],
            'base_url' => ['nullable', 'url'],
            'username' => ['nullable', 'string'],
            'password' => ['nullable', 'string'],
            'app_key' => ['nullable', 'string'],
            'app_secret' => ['nullable', 'string'],
            'webhook' => ['nullable', 'string'],
            'store_id' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'is_testmode' => ['boolean'],
            'is_localhost' => ['boolean'],
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,svg,webp', 'max:2048'],
        ]);

        $logoPath = $getway->logo;
        if ($request->hasFile('logo')) {
            if ($getway->logo) {
                FileUpload::deleteFile($getway->logo);
            }
            $logoPath = FileUpload::storeFile($request->file('logo'), 'uploads/getways');
        }

        $slug = $validated['name'] === $getway->name
            ? $getway->slug
            : Str::slug($validated['name']);

        $getway->update([
            'name' => $validated['name'],
            'slug' => $slug,
            'base_url' => $validated['base_url'] ?? null,
            'username' => $validated['username'] ?? null,
            'password' => $validated['password'] ?? null,
            'app_key' => $validated['app_key'] ?? null,
            'app_secret' => $validated['app_secret'] ?? null,
            'webhook' => $validated['webhook'] ?? null,
            'store_id' => $validated['store_id'] ?? null,
            'is_active' => $request->boolean('is_active', true),
            'is_testmode' => $request->boolean('is_testmode', true),
            'is_localhost' => $request->boolean('is_localhost', false),
            'logo' => $logoPath,
        ]);

        return $this->successResponse(
            'Gateway updated successfully',
            $getway
        );
    }

    public function status($id)
    {
        $getway = PaymentGetway::findOrFail($id);

        $getway->update([
            'is_active' => !$getway->is_active,
        ]);

        return $this->successResponse(
            'Gateway status updated successfully',
            $getway
        );
    }

    public function destroy($id)
    {
        $getway = PaymentGetway::findOrFail($id);

        if ($getway->logo) {
            FileUpload::deleteFile($getway->logo);
        }

        $getway->delete();

        return $this->successResponse(
            'Gateway deleted successfully'
        );
    }
}
