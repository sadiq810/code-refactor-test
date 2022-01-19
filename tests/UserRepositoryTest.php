<?php

namespace Tests\Unit;

use DTApi\Helpers\TeHelper;
use DTApi\Repository\UserRepository;
use PHPUnit\Framework\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UrlHelperTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test createOrUpdate function for creating new user.
     *
     * @return void
     */
    public function testCreateNewUser()
    {
        $userRepository = new UserRepository(new User);
        $request = new Request([
            'role' => 1,
            'name' => 'Test User',
            'company_id' => 1,
            'department_id' => 2,
            'email' => 'abc@gmail.com',
            'dob_or_orgid' => '1990-01-11',
            'phone' => '090923489384',
            'mobile' => '3472348928',
            'password' => 'sdsdfsdfsd',
            'consumer_type' => 'paid',
            'username' => 'sdfsdfsdf',
            'post_code' => '2345',
            'address' => 'some address',
            'city' => 'some city',
            'country' => 'usa'
        ]);

        $userResponse = $userRepository->createOrUpdate(null, $request);

        $this->assertNotNull($userResponse);

        $users = User::all();

        $this->assertCount(1, $users);

        $userMeta = UserMeta::all();

        $this->assertEquals($userMeta->count(), 1);
    }


    /**
     * Test createOrUpdate function for updating a user.
     *
     * @return void
     */
    public function testUpdateUser()
    {
        $userRepository = new UserRepository(new User);
        $request = new Request([
            'role' => 1,
            'name' => 'Test User',
            'company_id' => 1,
            'department_id' => 2,
            'email' => 'abc@gmail.com',
            'dob_or_orgid' => '1990-01-11',
            'phone' => '090923489384',
            'mobile' => '3472348928',
            'password' => 'sdsdfsdfsd',
            'consumer_type' => 'paid',
            'username' => 'sdfsdfsdf',
            'post_code' => '2345',
            'address' => 'some address',
            'city' => 'some city',
            'country' => 'usa'
        ]);

        $createdUser = $userRepository->createOrUpdate(null, $request);

        $this->assertNotNull($createdUser);

        $request = new Request([
            'name' => 'Test User Updated',
            'phone' => '090923484444',
            'mobile' => '3472341111'
        ]);

        $updatedUser = $userRepository->createOrUpdate($createdUser->id, $request);

        $this->assertEquals($updatedUser->name, 'Test User Updated');
        $this->assertEquals($updatedUser->phone, '090923484444');
        $this->assertEquals($updatedUser->mobile, '3472341111');
        $this->assertEquals($updatedUser->id, $updatedUser->id);
    }

    /**
     * Test createOrUpdate function for updating a non existing user.
     *
     * @return void
     */
    public function testUpdateNonExistingUser()
    {
        $userRepository = new UserRepository(new User);
        $request = new Request([
            'name' => 'Test User abc',
        ]);

        $userRepository->createOrUpdate(99999, $request);

        $this->expectException(ModelNotFoundException::class);
    }
}