<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeAdmin extends Command
{
    protected $signature = 'app:make-admin {email : The email of an existing user}';

    protected $description = 'Give an existing user access to the admin report';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No user with that email. Register first, then run this again.');

            return self::FAILURE;
        }

        // is_admin is not mass-assignable, on purpose: this command is the only way to set it.
        $user->is_admin = true;
        $user->save();

        $this->info("{$user->email} is an admin.");

        return self::SUCCESS;
    }
}
