<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\AuthRequest;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AuthMailService;
use App\Services\EmailVerificationService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(AuthRequest $request, AuthMailService $mail, EmailVerificationService $verification)
    {
        $data = $request->validated();
        $mail->mailer();
        if (User::whereRaw('lower(email) = ?', [$data['email']])->exists()) {
            throw ValidationException::withMessages(['email' => 'Email sudah terdaftar. Silakan masuk.']);
        }
        try {
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password'])]);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['email' => 'Pendaftaran belum dapat diproses. Coba kembali atau masuk jika sudah terdaftar.']);
        }
        $this->pending($request, $user);
        try {
            $verification->issue($user);
        } catch (ValidationException $exception) {
            return redirect()->route('verification.notice')->withErrors($exception->errors());
        }
        return redirect()->route('verification.notice')->with('status', 'Kode verifikasi telah dikirim. Periksa emailmu.');
    }

    public function login(AuthRequest $request)
    {
        $data = $request->validated();
        $user = User::whereRaw('lower(email) = ?', [$data['email']])->first();
        // Use the configured hasher even for unknown accounts; no hardcoded hash algorithm.
        if ($user) {
            $valid = Hash::check($data['password'], $user->password);
        } else {
            Hash::make($data['password']);
            $valid = false;
        }
        if (! $user || ! $valid || SocialAccount::where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'Email atau password yang dimasukkan salah.']);
        }
        if (! $user->email_verified_at) {
            $this->pending($request, $user);
            return redirect()->route('verification.notice')->with('status', 'Verifikasi email sebelum masuk. Jika perlu, minta kode baru.');
        }
        Auth::login($user);
        $request->session()->regenerate();
        $this->rememberPasswordVersion($request, $user);
        $request->session()->forget(['pending_verification_user', 'pending_verification_until']);
        return redirect()->route('home')->with('status', 'Selamat datang kembali!');
    }

    public function notice(Request $request)
    {
        $this->pendingUser($request);
        return view('auth.verify');
    }

    public function verify(AuthRequest $request, EmailVerificationService $verification)
    {
        $user = $this->pendingUser($request);
        if (! $verification->verify($user, $request->validated('otp'))) {
            throw ValidationException::withMessages(['otp' => 'Kode tidak valid, sudah digunakan, kedaluwarsa, atau batas percobaan tercapai. Minta kode baru jika diperlukan.']);
        }
        $user = $user->fresh();
        Auth::login($user);
        $request->session()->regenerate();
        $this->rememberPasswordVersion($request, $user);
        $request->session()->forget(['pending_verification_user', 'pending_verification_until']);
        return redirect()->route('home')->with('status', 'Email berhasil diverifikasi. Selamat memasak!');
    }

    public function resend(Request $request, EmailVerificationService $verification)
    {
        $verification->issue($this->pendingUser($request));
        return back()->with('status', 'Kode baru telah dikirim. Kode sebelumnya tidak berlaku.');
    }

    public function forgot(AuthRequest $request, AuthMailService $mail)
    {
        $message = 'Jika email ini terhubung dengan akun yang menggunakan password, link reset akan dikirim. Periksa kotak masuk atau folder spam. Jika belum diterima, tunggu satu menit sebelum mencoba lagi.';
        try {
            $mail->mailer();
            $user = User::where('email', $request->validated('email'))->first();
            if ($user && ! SocialAccount::where('user_id', $user->id)->exists()) {
                Password::broker()->sendResetLink(['email' => $user->email]);
            }
        } catch (\Throwable) {
            // Never report mail exceptions: they can include reset tokens/credentials.
        }
        return back()->with('status', $message);
    }

    public function resetForm(Request $request, string $token)
    {
        return view('auth.reset', ['token' => $token, 'email' => is_string($request->query('email')) ? $request->query('email') : '']);
    }

    public function reset(AuthRequest $request)
    {
        $data = $request->validated();
        $user = User::where('email', $data['email'])->first();
        $status = Password::INVALID_TOKEN;
        $expired = false;
        if ($user && ! SocialAccount::where('user_id', $user->id)->exists()) {
            // Classify only a matching stored token; the broker still authorizes the reset.
            $brokerConfig = config('auth.passwords.'.config('auth.defaults.passwords'));
            if (($brokerConfig['driver'] ?? 'database') === 'database') {
                $record = \Illuminate\Support\Facades\DB::connection($brokerConfig['connection'] ?? null)
                    ->table($brokerConfig['table'])->where('email', $user->getEmailForPasswordReset())->first();
                if ($record && Hash::check($data['token'], $record->token)) {
                    $expired = \Illuminate\Support\Carbon::parse($record->created_at)->addMinutes($brokerConfig['expire'])->isPast();
                }
            }
            $status = Password::broker()->reset($data, function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($user));
            });
        }
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => $expired
                ? 'Link reset password sudah kedaluwarsa. Silakan minta link baru.'
                : 'Link reset password tidak valid atau sudah digunakan. Silakan minta link baru.']);
        }
        // Reset never verifies email or signs the user in.
        return redirect()->route('login')->with('status', 'Password berhasil diperbarui. Silakan masuk menggunakan password baru.');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        // The encrypted dapur_guest cookie remains untouched.
        return redirect()->route('home');
    }

    private function rememberPasswordVersion(Request $request, User $user): void
    {
        $request->session()->put('password_hash_'.config('auth.defaults.guard'), Auth::guard()->hashPasswordForCookie($user->getAuthPassword()));
    }

    private function pending(Request $request, User $user): void
    {
        $request->session()->regenerate();
        $request->session()->put(['pending_verification_user' => $user->id, 'pending_verification_until' => now()->addMinutes(30)->timestamp]);
    }

    private function pendingUser(Request $request): User
    {
        if ((int) $request->session()->get('pending_verification_until', 0) < now()->timestamp) {
            abort(redirect()->route('login')->with('status', 'Sesi verifikasi berakhir. Masuk untuk melanjutkan verifikasi.'));
        }
        $user = User::find($request->session()->get('pending_verification_user'));
        if (! $user || $user->email_verified_at) {
            abort(redirect()->route('login'));
        }
        return $user;
    }
}
