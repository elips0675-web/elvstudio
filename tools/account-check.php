<?php

require __DIR__ . '/../bootstrap/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Elv\Kit\Classes\AccountService;
use Elv\Kit\Models\Customer;
use Elv\Kit\Models\Install;
use Elv\Kit\Models\License;
use Elv\Kit\Models\Order;

$errors = [];

$check = function (bool $condition, string $message) use (&$errors) {
    if (!$condition) {
        $errors[] = $message;
    }
};

$suffix = bin2hex(random_bytes(4));
$email = 'qa-account-' . $suffix . '@example.test';
$otherEmail = 'qa-account-other-' . $suffix . '@example.test';

$customer = Customer::create([
    'email' => $email,
    'name' => 'Алексей Константинович',
    'phone' => '+375 00 000-00-00',
]);

$check($customer->short_name === 'Алексей К.', 'short name is not reduced to «Имя Ф.»');
$check($customer->display_name === 'Алексей Константинович', 'display name is not the full name');

$token = $customer->issueLoginToken();
$check(strlen($token) === 48, 'login token is not 48 characters');
$check((bool) preg_match('~^[a-f0-9]{48}$~', $token), 'login token is not hex');
$check($customer->hasValidLoginToken(), 'issued token is not valid');
$check(str_contains($customer->magicLink(), '/account/auth/' . $token), 'magic link does not contain the token');
$check($customer->login_token_expires_at->diffInMinutes(now()) <= 31, 'token lifetime is longer than 30 minutes');

$service = new AccountService();
$sessionKey = AccountService::SESSION_KEY;
session()->forget($sessionKey);

$logged = $service->verify($token);
$check($logged instanceof Customer && $logged->id === $customer->id, 'valid token does not open the account');
$check($service->user() && $service->user()->id === $customer->id, 'account session is not set after login');
$check($customer->fresh()->login_token === null, 'login token is not cleared after use');
$check($customer->fresh()->last_login_at !== null, 'last login time is not saved');

$again = $service->verify($token);
$check($again === null, 'used token opens the account twice');

$service->logout();
$check($service->user() === null, 'logout does not clear the account session');

$expiredToken = $customer->issueLoginToken();
$customer->forceFill(['login_token_expires_at' => now()->subMinute()])->save();
$check($service->verify($expiredToken) === null, 'expired token opens the account');

$other = Customer::create(['email' => $otherEmail]);

$order = Order::create([
    'order_no' => 'QA-' . strtoupper($suffix),
    'product_name' => 'Elv Kit',
    'plan' => 'single',
    'qty' => 1,
    'amount_cents' => 9900,
    'currency' => 'USD',
    'status' => Order::STATUS_PAID,
    'customer_name' => 'Алексей Константинович',
    'email' => $email,
    'paid_at' => now(),
]);

$license = new License();
$license->license_key = 'ELV-QA' . strtoupper($suffix) . '-0000-0000';
$license->product_slug = 'elv-kit';
$license->email = $email;
$license->order_id = $order->id;
$license->domain_limit = 2;
$license->status = License::STATUS_ACTIVE;
$license->save();

$install = Install::create([
    'license_id' => $license->id,
    'product_slug' => 'elv-kit',
    'domain_hash' => hash('sha256', 'qa-' . $suffix . '.example.test'),
    'domain' => 'qa-' . $suffix . '.example.test',
    'app_version' => '1.1.0',
]);

$check($service->orders($customer)->count() === 1, 'cabinet does not show the customer order');
$check($service->licenses($customer)->count() === 1, 'cabinet does not show the customer license');
$check($service->installs($customer)->count() === 1, 'cabinet does not show the customer domain');
$check($service->orders($other)->count() === 0, 'another customer sees the order');
$check($service->licenses($other)->count() === 0, 'another customer sees the license');
$check($service->installs($other)->count() === 0, 'another customer sees the domain');

$check($service->canUnbind($customer, $install)['ok'] === true, 'first unbind is blocked');
$check($service->canUnbind($other, $install)['ok'] === false, 'another customer can unbind the domain');

$result = $service->unbind($customer, $install);
$check($result['ok'] === true, 'domain unbind failed: ' . $result['message']);
$check(Install::query()->find($install->id) === null, 'unbound domain is still in the database');
$check($license->fresh()->domain_changed_at !== null, 'unbind date is not saved on the license');
$check($service->canUnbind($customer, $install)['ok'] === false, 'second unbind within 30 days is allowed');
$check($service->nextUnbindAt($license->fresh()) !== null, 'next unbind date is not calculated');

$license->fresh()->forceFill(['domain_changed_at' => now()->subDays(31)])->save();
$newInstall = Install::create([
    'license_id' => $license->id,
    'product_slug' => 'elv-kit',
    'domain_hash' => hash('sha256', 'qa-new-' . $suffix . '.example.test'),
    'domain' => 'qa-new-' . $suffix . '.example.test',
]);
$check($service->canUnbind($customer, $newInstall)['ok'] === true, 'unbind stays blocked after 30 days');

$legacy = new Install();
$legacy->domain = '';
$check($legacy->domain_label !== '', 'legacy install without a domain has no label');

Install::query()->whereIn('license_id', [$license->id])->delete();
License::query()->where('id', $license->id)->delete();
Order::query()->where('id', $order->id)->delete();
Customer::query()->whereIn('email', [$email, $otherEmail])->delete();
session()->forget($sessionKey);

if ($errors !== []) {
    fwrite(STDERR, "account checks failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "OK: account checks passed\n";
