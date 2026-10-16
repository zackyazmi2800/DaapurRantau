<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class AuthRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        $passwordBytes = function ($attribute, $value, $fail) {
            if (config('hashing.driver', 'bcrypt') === 'bcrypt' && is_string($value) && strlen($value) > 72) {
                $fail('Password maksimal 72 byte. Kurangi panjang atau karakter multibyte.');
            }
        };

        $notBlank = function ($attribute, $value, $fail) {
            if (is_string($value) && preg_match('/\A\s+\z/u', $value)) {
                $fail('Password tidak boleh hanya berisi spasi.');
            }
        };

        return match ($this->route()->getName()) {
            'register.store' => ['name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'], 'password' => ['bail', 'required', 'string', $notBlank, 'min:8', 'max:64', 'confirmed', $passwordBytes]],
            'login.store' => ['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:128']],
            'verification.verify' => ['otp' => ['required', 'string', 'regex:/\A[0-9]{6}\z/']],
            'password.email' => ['email' => ['required', 'email', 'max:255']],
            'password.update' => ['email' => ['required', 'email', 'max:255'], 'token' => ['required', 'string', 'max:255'], 'password' => ['bail', 'required', 'string', $notBlank, 'min:8', 'max:64', 'confirmed', $passwordBytes]],
            default => [],
        };
    }

    public function messages(): array
    {
        return ['password.required' => is_string($this->input('password')) && preg_match('/\A\s+\z/u', $this->input('password')) ? 'Password tidak boleh hanya berisi spasi.' : 'Password wajib diisi.', 'password.min' => 'Password harus terdiri dari minimal 8 karakter.', 'password.max' => $this->routeIs('login.store') ? 'Password terlalu panjang.' : 'Password maksimal 64 karakter.', 'required' => ':attribute wajib diisi.', 'email' => 'Masukkan alamat email yang valid.', 'unique' => 'Email sudah terdaftar. Silakan masuk atau pulihkan password.', 'max' => ':attribute terlalu panjang.', 'string' => ':attribute tidak valid.', 'confirmed' => 'Konfirmasi password tidak sesuai.', 'min' => ':attribute minimal :min karakter.', 'password.letters' => 'Password harus mengandung huruf.', 'password.numbers' => 'Password harus mengandung angka.', 'otp.regex' => 'Kode OTP harus terdiri dari 6 digit.'];
    }

    public function attributes(): array { return ['name' => 'Nama', 'email' => 'Email', 'password' => 'Password', 'otp' => 'Kode OTP', 'token' => 'Token reset']; }
}
