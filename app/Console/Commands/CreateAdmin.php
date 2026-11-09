<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'vsla:admin {email?} {--name=Administrator}';

    protected $description = 'Membuat akun admin VSLA pertama atau tambahan tanpa data contoh';

    public function handle(): int
    {
        $email = $this->argument('email') ?? $this->ask('Email admin');
        $password = $this->secret('Kata sandi (minimal 12 karakter)');
        $confirm = $this->secret('Ulangi kata sandi');
        $v = Validator::make(['email' => $email, 'password' => $password, 'password_confirmation' => $confirm, 'name' => $this->option('name')], ['email' => 'required|email|unique:users,email', 'password' => 'required|string|min:12|confirmed', 'name' => 'required|string|max:255']);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $user = new User;
        $user->name = $this->option('name');
        $user->email = $email;
        $user->password = Hash::make($password);
        $user->is_admin = true;
        $user->save();
        $this->info('Akun admin dibuat. Silakan masuk melalui /login.');

        return self::SUCCESS;
    }
}
