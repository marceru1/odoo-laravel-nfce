<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdminUser extends Command
{
    protected $signature = 'make:admin {name} {email} {password}';

    protected $description = 'Cria um usuário administrador para o Painel Fiscal';

    /**
     * Creates an admin user for the Fiscal Dashboard.
     * Usage: php artisan make:admin "Nome" email@exemplo.com senha
     */
    public function handle(): int
    {
        $name     = $this->argument('name');
        $email    = $this->argument('email');
        $password = $this->argument('password');

        if (User::where('email', $email)->exists()) {
            $this->error("Já existe um usuário com o e-mail: {$email}");

            return self::FAILURE;
        }

        $user = User::create([
            'name'     => $name,
            'email'    => $email,
            'password' => Hash::make($password),
        ]);

        $this->info("Usuário {$user->email} criado com sucesso!");

        return self::SUCCESS;
    }
}
