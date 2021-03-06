<?php

namespace Tests\Feature\Api\v1;

use App\Components\Push\Facades\Push;
use App\Http\Middleware\CredentialsApiKey;
use App\Jobs\SendPushJob;
use App\Models\Credential;
use App\Models\PushNotificationLog;
use App\Models\PushNotificationSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PushFeatureTest extends TestCase
{
    /** @var Credential $credential */
    private $credential = null;

    private $headers = [];

    protected function setUp(): void
    {
        parent::setUp(); // TODO: Change the autogenerated stub
        $this->credential = factory(Credential::class)->create();
        factory(PushNotificationSetting::class)->create([
            'credential_id' => $this->credential->id
        ]);
        $this->headers = [
            CredentialsApiKey::AUTH_HEADER => $this->credential->api_key
        ];
    }

    private function validPush()
    {
        return [
            'to' => json_encode(['UID:1', 'UID:2']),
            'payload' => json_encode([
                'notification' => [
                    'title' => '',
                    'body' => '',
                    'click_action' => '',
                ],
                'data' => [
                    'a' => 'A',
                    'b' => 'B',
                    'c' => 'C'
                ],

            ])
        ];
    }

    private function validDevice()
    {
        return [
            'platform' => 'android',
            'identity' => '123456789012345',
            'regid' => 'regidregidregidregidregidregidregidregid'
        ];
    }

    public function test_it_can_list_latest_push_statuses()
    {
        factory(PushNotificationLog::class, 10)->create([
            'credential_id' => $this->credential->id
        ]);
        $response = $this->get(route('api.push'), $this->headers);
        $response->assertStatus(200);
        $response->assertJsonCount(10, 'data');
    }

    public function test_it_can_list_a_push_status()
    {
        /** @var $notification PushNotificationLog */
        $notification = factory(PushNotificationLog::class)->create([
            'credential_id' => $this->credential->id
        ]);
        $response = $this->get(route('api.push.status', ['uuid' => $notification->id]), $this->headers);
        $response->assertStatus(200);
        $response->assertJson([
            'push_uuid' => $notification->id,
            'status' => $notification->status
        ]);
    }

    public function test_it_can_register_a_device()
    {
        $device = $this->validDevice();
        $response = $this->post(route('api.push.register'), $device, $this->headers);
        $response->assertStatus(200);
        $response->assertJson([
            'device_uuid' => "UID:" . sha1($this->credential["id"] . $device["platform"] . $device["identity"]),
        ]);
    }

    public function test_that_an_existing_device_does_not_generate_a_new_uuid()
    {
        $device = $this->validDevice();
        $response = $this->post(route('api.push.register'), $device, $this->headers);
        $response->assertStatus(200);
        $response->assertJson([
            'device_uuid' => "UID:" . sha1($this->credential["id"] . $device["platform"] . $device["identity"]),
        ]);
        $device["regid"] = $this->faker->sentence();
        $response = $this->post(route('api.push.register'), $device, $this->headers);
        $response->assertJson([
            'device_uuid' => "UID:" . sha1($this->credential["id"] . $device["platform"] . $device["identity"]),
        ]);
    }

    public function test_it_trows_404_on_unknown_id()
    {
        $response = $this->get(route('api.push.status', ['uuid' => 'non-existing_UUID']), $this->headers);
        $response->assertStatus(404);
    }

    public function test_it_trows_403_if_an_invalid_or_no_api_key_is_specified()
    {
        $response = $this->get(route('api.push'));
        $response->assertStatus(403);
    }

    public function test_it_cant_send_a_push_without_a_device()
    {
        $push = $this->validPush();
        Arr::forget($push, 'to');
        $response = $this->post(route('api.push.now'), $push, $this->headers);
        $response->assertStatus(422);
    }

    public function test_it_cant_send_a_push_without_payload()
    {
        $push = $this->validPush();
        Arr::forget($push, 'payload');
        $response = $this->post(route('api.push.now'), $push, $this->headers);
        $response->assertStatus(422);
    }

    public function test_it_can_queue_a_push()
    {
        $device = $this->validDevice();
        $response = $this->post(route('api.push.register'), $device, $this->headers);
        $response->assertStatus(200);
        $response->assertJson([
            'device_uuid' => "UID:" . sha1($this->credential["id"] . $device["platform"] . $device["identity"]),
        ]);

        $push = $this->validPush();
        $push['to'] = json_encode([
            "UID:" . sha1($this->credential["id"] . $device["platform"] . $device["identity"])
        ]);
        Queue::fake();
        $response = $this->post(route('api.push.queue'), $push, $this->headers);
        Queue::assertPushed(SendPushJob::class, 1);
        $response->assertStatus(200);
        $response->assertJsonPath('status', 'queued');

    }

    public function test_it_can_send_a_push()
    {
        $device = $this->validDevice();
        $response = $this->post(route('api.push.register'), $device, $this->headers);
        $response->assertStatus(200);
        $response->assertJson([
            'device_uuid' => "UID:" . sha1($this->credential["id"] . $device["platform"] . $device["identity"]),
        ]);

        Push::fake();
        $push = $this->validPush();
        $push['to'] = json_encode([
            "UID:" . sha1($this->credential["id"] . $device["platform"] . $device["identity"])
        ]);
        $response = $this->post(route('api.push.now'), $push, $this->headers);
        Push::assertSent([$device["regid"]], count(json_decode($push['to'])));
        $response->assertStatus(200);
        $response->assertJsonPath('status', 'sent');
    }
}
