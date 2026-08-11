<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Notifications\WelcomeUser;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * The form's welcome-email toggle, held between the form state being
     * mutated and the record existing.
     *
     * It is a field on the form but not a column on the model, so it has to
     * come off the payload before the insert; `afterCreate` is the first point
     * where there is an account to notify.
     */
    private bool $sendWelcomeEmail = false;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->sendWelcomeEmail = (bool) ($data['send_welcome_email'] ?? false);

        unset($data['send_welcome_email']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $user = $this->getRecord();

        // The page's record is only typed as a Model; this resource never
        // creates anything else, and narrowing here beats asserting it.
        if ($this->sendWelcomeEmail && $user instanceof User) {
            $user->notify(new WelcomeUser);
        }
    }
}
