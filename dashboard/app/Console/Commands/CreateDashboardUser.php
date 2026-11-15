<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateDashboardUser extends Command
{
    protected $signature = 'dashboard:user
                            {email? : Operator email address}
                            {--name= : Display name}
                            {--password= : Password (prompted when omitted; avoid passing secrets on the command line)}';

    protected $description = 'Create a dashboard operator account, or reset the password of an existing one';

    public function handle(): int
    {
        $email = $this->argument('email') ?? text('Email', required: true);
        $name = $this->option('name') ?? text('Name', default: strstr($email, '@', true) ?: $email, required: true);
        $secret = $this->option('password') ?? password('Password (min. 12 characters)', required: true);

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $secret],
            [
                'email'    => ['required', 'email', 'max:255'],
                'name'     => ['required', 'string', 'max:255'],
                'password' => ['required', Password::min(12)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::updateOrCreate(['email' => $email], ['name' => $name, 'password' => $secret]);

        $this->info(($user->wasRecentlyCreated ? 'Created' : 'Updated').' dashboard operator '.$email.'.');

        return self::SUCCESS;
    }
}
