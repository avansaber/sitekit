<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class MakeSitekitUser extends Command
{
    protected $signature = 'sitekit:make-user
                            {--name= : The operator name}
                            {--email= : The operator email address}
                            {--password= : The operator password (minimum 8 characters)}';

    protected $description = 'Create the sole SiteKit operator account';

    public function handle(): int
    {
        if (User::exists()) {
            $this->components->error('A SiteKit account already exists. Public and additional user creation are disabled.');

            return self::FAILURE;
        }

        $name = $this->option('name') ?: text(label: 'Name', required: true);
        $email = $this->option('email') ?: text(
            label: 'Email address',
            required: true,
            validate: fn (string $email): ?string => filter_var($email, FILTER_VALIDATE_EMAIL)
                ? null
                : 'The email address must be valid.',
        );
        $plainPassword = $this->option('password') ?: password(label: 'Password', required: true);

        if (User::where('email', $email)->exists()) {
            $this->components->error('A user with this email address already exists.');

            return self::FAILURE;
        }

        if (strlen($plainPassword) < 8) {
            $this->components->error('The password must be at least 8 characters.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($name, $email, $plainPassword): void {
            $user = User::forceCreate([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($plainPassword),
                'email_verified_at' => now(),
            ]);

            $team = $user->ownedTeams()->create([
                'name' => explode(' ', $user->name, 2)[0]."'s Team",
                'personal_team' => true,
            ]);

            $user->switchTeam($team);
        });

        $this->components->info("Operator account created. Log in at ".url('/app/login'));

        return self::SUCCESS;
    }
}
