<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * The signed-in user's own profile, password and language.
 */
class UserController extends Controller
{
    public function getProfile()
    {
        return view('user.profile', ['user' => auth()->user()]);
    }

    public function updateProfile(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'surname' => 'nullable|string|max:255',
            'first_name' => 'required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'contact_no' => 'nullable|string|max:15',
            'address' => 'nullable|string|max:1000',
            'language' => 'required|string|in:'.implode(',', array_keys(config('constants.langs'))),
        ]);

        $user->update($validated);

        // Keep the session copy in step or the UI shows stale details.
        $request->session()->forget('user');

        return back()->with('status', $this->ok(__('lang_v1.updated_successfully')));
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|current_password',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = auth()->user();
        $user->password = Hash::make($request->string('password'));
        $user->save();

        return back()->with('status', $this->ok(__('lang_v1.password_updated')));
    }

    /**
     * Quick locale switch from the header. Persisted so it survives login.
     */
    public function switchLanguage(Request $request)
    {
        $validated = $request->validate([
            'language' => 'required|string|in:'.implode(',', array_keys(config('constants.langs'))),
        ]);

        $user = auth()->user();
        $user->language = $validated['language'];
        $user->save();

        $request->session()->put('user.language', $validated['language']);

        return back();
    }
}
