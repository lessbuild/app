<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\CurrentAdmin;
use App\Services\Admin\EmailDelivery;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** How email is sent, what would stop it arriving, and a test to send. Secrets are never shown. */
final class Email extends Page
{
    /**
     * The page's Blade view.
     *
     * @var string
     */
    protected string $view = 'filament.pages.email';

    /**
     * The sidebar icon.
     *
     * @var string|BackedEnum|null
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    /**
     * The sidebar group.
     *
     * @var string|UnitEnum|null
     */
    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    /**
     * The position in the group.
     *
     * @var int|null
     */
    protected static ?int $navigationSort = 40;

    /**
     * Say what the page is for.
     *
     * @return string
     */
    public function getSubheading(): string
    {
        return __('How email is sent, what would stop it arriving, and a test you can send. Secrets are never shown.');
    }

    /**
     * Get the header's button: send a test email straight away (not queued, so any error shows).
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')->label(__('Send a test email'))->icon(Heroicon::OutlinedPaperAirplane)
                ->fillForm(fn (): array => ['to' => CurrentAdmin::user()->email])
                ->schema([TextInput::make('to')->label(__('Send to'))->email()->required()->maxLength(254)])
                ->action(function (array $data): void {
                    $result = app(EmailDelivery::class)->sendTest(CurrentAdmin::user(), (string) $data['to']);
                    $result['sent']
                        ? Notification::make()->success()->title(__('Sent to :to.', ['to' => $result['to']]))->body(__('If it doesn’t arrive within a few minutes, check the spam folder and the mail provider’s logs.'))->send()
                        : Notification::make()->danger()->title(__('Sending failed: :error', ['error' => $result['error']]))->persistent()->send();
                }),
        ];
    }

    /**
     * Give the view the settings, the problems found and the last test.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $email = app(EmailDelivery::class);

        return ['settings' => $email->settings(), 'problems' => $email->problems(), 'lastTest' => $email->lastTest()];
    }
}
