<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Illuminate\Support\Carbon;
use Fleetbase\Events\AccountCreated;
use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Requests\OnboardRequest;
use Fleetbase\Models\User;
use Fleetbase\Models\Company;
use Fleetbase\Models\CompanyUser;
use Fleetbase\Models\VerificationCode;
use Illuminate\Http\Request;

class OnboardController extends Controller
{
    /**
     * Checks to see if this is the first time Fleetbase is being used by checking if any organizations exists.
     *
     * @return \Illuminate\Http\Response
     */
    public function shouldOnboard()
    {
        return response()->json(
            [
                'should_onboard' => Company::doesntExist()
            ]
        );
    }

    /**
     * Onboard a new account and send send to verify email.
     *
     * @param \Fleetbase\Http\Requests\OnboardRequest $request
     * @return \Illuminate\Http\Response
     */
    public function createAccount(OnboardRequest $request)
    {
        // create user account
        $user = User::create([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'phone' => $request->input('phone'),
            'password' => $request->input('password'),
            'status' => 'active',
            'type' => 'user'
        ]);

        // the default trial duration
        $trialDuration = config('app.trial_duration');

        // create company
        $company = new Company(['name' => $request->input('organization_name'), 'trial_ends_at' => now()->addDays($trialDuration)]);
        $company->setOwner($user)->save();

        // create as stripe customer
        $company->createAsStripeCustomer([
            'email' => $user->email,
            'description' => 'Customer for ' . $company->name
        ]);

        // assign user to organization
        $user->assignCompany($company);

        // create company user
        CompanyUser::create([
            'user_uuid' => $user->uuid,
            'company_uuid' => $company->uuid,
            'status' => 'active'
        ]);

        // send account created event
        event(new AccountCreated($user, $company));

        // create verification code
        VerificationCode::generateEmailVerificationFor($user);

        // create auth token
        $token = $user->createToken($request->ip());

        return response()->json([
            'status' => 'success',
            'session' => $user->uuid,
            'token' => $token->plainTextToken
        ]);
    }

    /**
     * Send/Resend verification email
     * 
     * @param \\Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response $response
     */
    public function sendVerificationEmail(Request $request)
    {
        $user = $request->user() ?? User::where('uuid', session('user'))->first();
        $email = $request->input('email');

        if ($user) {
            // check if email needs to be updated
            if ($email && $email !== $user->email) {
                $user->email = $email;
                $user->save();
            }

            // create verification code
            VerificationCode::generateEmailVerificationFor($user);
        }

        return response()->json([
            'status' => 'success'
        ]);
    }

    /**
     * Send/Resend verification SMS
     * 
     * @param \\Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response $response
     */
    public function sendVerificationSms(Request $request)
    {
        $user = $request->user() ?? User::where('uuid', session('user'))->first();
        $phone = $request->input('phone');

        if ($user) {
            // check if phone needs to be updated
            if ($phone && $phone !== $user->phone) {
                $user->phone = $phone;
                $user->save();
            }

            // create verification code
            VerificationCode::generateSmsVerificationFor($user);
        }

        return response()->json([
            'status' => 'success'
        ]);
    }

    /**
     * Verfiy and validate an email address with code.
     *
     * @param \\Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response $response
     */
    public function verifyEmail(Request $request)
    {
        // users uuid as session
        $session = $request->input('session') ?? session('user');
        $code = $request->input('code');

        // make sure session is found
        if (!$session) {
            return response()->error('No session to verify email for.');
        }

        // get verification code for session
        $verifyCode = VerificationCode::where([
            'subject_uuid' => $session,
            'for' => 'email_verification',
            'code' => $code
        ])->first();

        // check if sms verification
        if (!$verifyCode) {
            $verifyCode = VerificationCode::where([
                'subject_uuid' => $session,
                'for' => 'phone_verification',
                'code' => $code
            ])->first();
        }

        // no verification code found
        if (!$verifyCode) {
            return response()->error('Invalid verification code.');
        }

        // get user
        $user = $request->user() ?? User::where('uuid', $session)->first();

        // get verify time 
        $verifiedAt = Carbon::now();

        // verify users email address or phone depending
        if ($verifyCode->for === 'email_verification') {
            $user->email_verified_at = $verifiedAt;
        } else if ($verifyCode->for === 'phone_verification') {
            $user->phone_verified_at = $verifiedAt;
        }

        $user->status = 'active';
        $user->save();

        return response()->json([
            'status' => 'success',
            'verified_at' => $verifiedAt
        ]);
    }
}
