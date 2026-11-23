<?php

namespace App\Console\Commands;

use App\Actions\Users\CreateSuperAdmin;
use App\Concerns\ProfileValidationRules;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('admin:create
    {--name= : The name of the super admin}
    {--email= : The email address of the super admin}
    {--password= : The password (omit to be prompted securely)}')]
#[Description('Create a user with the super-admin role')]
class CreateAdmin extends Command
{
    use ProfileValidationRules;

    /**
     * Execute the console command.
     */
    public function handle(CreateSuperAdmin $createSuperAdmin): int
    {
        $data = [
            'name' => $this->option('name') ?? ($this->input->isInteractive() ? text('Name', required: true) : null),
            'email' => $this->option('email') ?? ($this->input->isInteractive() ? text('Email', required: true) : null),
            'password' => $this->option('password') ?? ($this->input->isInteractive() ? password('Password', required: true) : null),
        ];

        $data['email'] = is_string($data['email']) ? Str::lower($data['email']) : $data['email'];

        $validator = Validator::make($data, [
            'name' => $this->nameRules(),
            'email' => $this->emailRules(),
            'password' => ['required', 'string', Password::default()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        /** @var array{name: string, email: string, password: string} $validated */
        $validated = $validator->validated();

        $user = $createSuperAdmin->handle($validated);

        $this->components->info("Super admin [{$user->email}] created.");

        return self::SUCCESS;
    }
}
