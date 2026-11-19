<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ];
    }

    /**
     * Sign the user in on the session guard, refusing deactivated accounts with the same message as bad credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $signedIn = Auth::guard('web')->attemptWhen(
            $this->only('email', 'password'),
            fn (User $user): bool => $user->canSignIn(),
            $this->boolean('remember'),
        );

        if (! $signedIn) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }
    }
}
