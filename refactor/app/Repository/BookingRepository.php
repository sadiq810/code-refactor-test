<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{
    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }//..... end of __construct() .....//

    /**
     * @param $user_id
     * @return array
     * Get specific user jobs.
     */
    public function getUsersJobs($user_id): array
    {
        $cuser = User::findOrFail($user_id);
        $usertype = '';
        $emergencyJobs = array();
        $noramlJobs = array();
        if ($cuser && $cuser->isCustomer()) {
            $jobs = $cuser->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
                ->whereIn('status', ['pending', 'assigned', 'started'])
                ->orderBy('due', 'asc')
                ->get();

            $usertype = 'customer';
        } elseif ($cuser && $cuser->is('translator')) {
            $jobs = Job::getTranslatorJobs($cuser->id, 'new');
            $jobs = $jobs->pluck('jobs')->all();

            $usertype = 'translator';
        }//..... end if-else() .....//

        if ($jobs) {
            foreach ($jobs as $jobitem) {
                if ($jobitem->immediate == 'yes') {
                    $emergencyJobs[] = $jobitem;
                } else {
                    $noramlJobs[] = $jobitem;
                }//..... end if-else() ....//
            }//..... end of foreach() .....//

            $noramlJobs = collect($noramlJobs)
                ->each(function ($item, $key) use ($user_id) {
                    $item['usercheck'] = Job::checkParticularJob($user_id, $item);
                })
                ->sortBy('due')
                ->all();
        }//..... end if() .....//

        return ['emergencyJobs' => $emergencyJobs, 'noramlJobs' => $noramlJobs, 'cuser' => $cuser, 'usertype' => $usertype];
    }//..... end of getUsersJobs() .....//

    /**
     * @param $user_id
     * @return array
     * Get user job History.
     */
    public function getUsersJobsHistory(Request $request): array
    {
        $cuser = User::find($request->user_id);

        if (!$cuser) return [];

        if ($cuser && $cuser->isCustomer()) {
            $jobs = $cuser->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                ->orderBy('due', 'desc')
                ->paginate(15);

            return [
                'emergencyJobs' => [],
                'noramlJobs'    => [],
                'jobs'          => $jobs,
                'cuser'         => $cuser,
                'usertype'      => 'customer',
                'numpages'      => 0,
                'pagenum'       => 0
            ];
        } elseif ($cuser && $cuser->isTranslator()) {
            $jobs_ids = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $request->page || 1);

            return [
                'emergencyJobs' => [],
                'noramlJobs'    => $jobs_ids,
                'jobs'          => $jobs_ids,
                'cuser'         => $cuser,
                'usertype'      => 'translator',
                'numpages'      => ceil($jobs_ids->total() / 15),
                'pagenum'       => $request->page || 1
            ];
        }

        return [];
    }//..... end of getUsersJobsHistory() .....//

    /**
     * @param $cuser
     * @param $data
     * @return mixed
     */
    public function store($cuser, $data)
    {
        $immediatetime = 5;
        $consumer_type = $cuser->userMeta->consumer_type;

        if ($cuser->user_type == env('CUSTOMER_ROLE_ID')) {
            if (!isset($data['from_language_id'])) {
                $response['status'] = 'fail';
                $response['message'] = "Du måste fylla in alla fält";
                $response['field_name'] = "from_language_id";
                return $response;
            }//..... end if() ....//

            if ($data['immediate'] == 'no') {
                if (isset($data['due_date']) && $data['due_date'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "due_date";
                    return $response;
                }//..... end if() ....//

                if (isset($data['due_time']) && $data['due_time'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "due_time";
                    return $response;
                }//..... end if() ....//

                if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type'])) {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste göra ett val här";
                    $response['field_name'] = "customer_phone_type";
                    return $response;
                }//..... end if() ....//

                if (isset($data['duration']) && $data['duration'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "duration";
                    return $response;
                }//..... end if() ....//

            } else {
                if (isset($data['duration']) && $data['duration'] == '') {
                    $response['status'] = 'fail';
                    $response['message'] = "Du måste fylla in alla fält";
                    $response['field_name'] = "duration";
                    return $response;
                }//..... end if() ....//
            }//..... end if-else() .....//

            if (isset($data['customer_phone_type'])) {
                $data['customer_phone_type'] = 'yes';
            } else {
                $data['customer_phone_type'] = 'no';
            }//..... end if-else() .....//

            if (isset($data['customer_physical_type'])) {
                $data['customer_physical_type'] = 'yes';
                $response['customer_physical_type'] = 'yes';
            } else {
                $data['customer_physical_type'] = 'no';
                $response['customer_physical_type'] = 'no';
            }//..... end if-else() .....//

            if ($data['immediate'] == 'yes') {
                $due_carbon = Carbon::now()->addMinute($immediatetime);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');
                $data['immediate'] = 'yes';
                $data['customer_phone_type'] = 'yes';
                $response['type'] = 'immediate';
            } else {
                $due = $data['due_date'] . " " . $data['due_time'];
                $response['type'] = 'regular';
                $due_carbon = Carbon::createFromFormat('m/d/Y H:i', $due);
                $data['due'] = $due_carbon->format('Y-m-d H:i:s');

                if ($due_carbon->isPast()) {
                    $response['status'] = 'fail';
                    $response['message'] = "Can't create booking in past";
                    return $response;
                }//..... end if() .....//
            }//..... end if-else() .....//

            if (in_array('male', $data['job_for'])) {
                $data['gender'] = 'male';
            } else if (in_array('female', $data['job_for'])) {
                $data['gender'] = 'female';
            }//..... end if-else() .....//

            if (in_array('normal', $data['job_for'])) {
                $data['certified'] = 'normal';
            } else if (in_array('certified', $data['job_for'])) {
                $data['certified'] = 'yes';
            } else if (in_array('certified_in_law', $data['job_for'])) {
                $data['certified'] = 'law';
            } else if (in_array('certified_in_helth', $data['job_for'])) {
                $data['certified'] = 'health';
            }//..... end if-else() .....//

            if (in_array('normal', $data['job_for']) && in_array('certified', $data['job_for'])) {
                $data['certified'] = 'both';
            } else if(in_array('normal', $data['job_for']) && in_array('certified_in_law', $data['job_for'])) {
                $data['certified'] = 'n_law';
            } else if(in_array('normal', $data['job_for']) && in_array('certified_in_helth', $data['job_for'])) {
                $data['certified'] = 'n_health';
            }//..... end if-else() .....//

            if ($consumer_type == 'rwsconsumer')
                $data['job_type'] = 'rws';
            else if ($consumer_type == 'ngo')
                $data['job_type'] = 'unpaid';
            else if ($consumer_type == 'paid')
                $data['job_type'] = 'paid';

            $data['b_created_at'] = date('Y-m-d H:i:s');

            if (isset($due))
                $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);

            $data['by_admin'] = isset($data['by_admin']) ? $data['by_admin'] : 'no';

            $job = $cuser->jobs()->create($data);

            $response['status'] = 'success';
            $response['id'] = $job->id;
            $data['job_for'] = array();

            if ($job->gender != null) {
                if ($job->gender == 'male') {
                    $data['job_for'][] = 'Man';
                } else if ($job->gender == 'female') {
                    $data['job_for'][] = 'Kvinna';
                }//..... end if-else() .....//
            }//..... end if() .....//

            if ($job->certified != null) {
                if ($job->certified == 'both') {
                    $data['job_for'][] = 'normal';
                    $data['job_for'][] = 'certified';
                } else if ($job->certified == 'yes') {
                    $data['job_for'][] = 'certified';
                } else {
                    $data['job_for'][] = $job->certified;
                }//..... end if-else() .....//
            }//..... end if() .....//

            $data['customer_town'] = $cuser->userMeta->city;
            $data['customer_type'] = $cuser->userMeta->customer_type;
        } else {
            $response['status'] = 'fail';
            $response['message'] = "Translator can not create booking";
        }

        return $response;
    }//.... end of store() .....//

    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        $user_type = $data['user_type'];
        $job = Job::findOrFail(@$data['user_email_job_id']);

        $job->user_email = @$data['user_email'];
        $job->reference = isset($data['reference']) ? $data['reference'] : '';
        $user = $job->user()->get()->first();

        if (isset($data['address'])) {
            $job->address = ($data['address'] != '') ? $data['address'] : $user->userMeta->address;
            $job->instructions = ($data['instructions'] != '') ? $data['instructions'] : $user->userMeta->instructions;
            $job->town = ($data['town'] != '') ? $data['town'] : $user->userMeta->city;
        }//..... end if() .....//

        $job->save();

        if (!empty($job->user_email)) {
            $email = $job->user_email;
            $name = $user->name;
        } else {
            $email = $user->email;
            $name = $user->name;
        }//..... end if-else() .....//

        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.job-created', [ 'user' => $user, 'job'  => $job ]);

        $data = $this->jobToData($job);

        Event::fire(new JobWasCreated($job, $data, '*'));

        return ['type' => $user_type, 'job' => $job, 'status' => 'success'];
    }//..... end of storeJobEmail() .....//

    /**
     * @param $job
     * @return array
     * Job to Data conversion.
     */
    public function jobToData($job): array
    {
        $due_Date = explode(" ", $job->due);

        $data = [
            'job_id'                 => $job->id,
            'from_language_id'       => $job->from_language_id,
            'immediate'              => $job->immediate,
            'duration'               => $job->duration,
            'status'                 => $job->status,
            'gender'                 => $job->gender,
            'certified'              => $job->certified,
            'due'                    => $job->due,
            'job_type'               => $job->job_type,
            'customer_phone_type'    => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town'          => $job->town,
            'customer_type'          => $job->user->userMeta->customer_type,
            'due_date'               => $due_Date[0],
            'due_time'               => $due_Date[1]
        ];

        $data['job_for'] = [];

        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } else if ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }//.... end if() ....//

        if ($job->certified != null) {
            switch ($job->certified) {
                case 'both':
                    $data['job_for'][] = 'Godkänd tolk';
                    $data['job_for'][] = 'Auktoriserad';
                    break;
                case 'yes':
                    $data['job_for'][] = 'Auktoriserad';
                    break;
                case 'n_health':
                    $data['job_for'][] = 'Sjukvårdstolk';
                    break;
                case 'law':
                case 'n_law':
                    $data['job_for'][] = 'Rätttstolk';
                    break;
                default:
                    $data['job_for'][] = $job->certified;
            }//..... end of switch() ....//
        }//.... end if() ....//

        return $data;
    }//..... end of jobToData() .....//

    /**
     * @param array $post_data
     */
    public function jobEnd($post_data = array())
    {
        $job = Job::with('translatorJobRel')->find($post_data["job_id"]);
        $diff = date_diff(date_create(date('Y-m-d H:i:s')), date_create($job->due));
        $job->session_time = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';

        $user = $job->user()->get()->first();
        $email = (!empty($job->user_email)) ? $job->user_email : $user->email;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';

        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];

        (new AppMailer())->send($email, $user->name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($job, ($post_data['userid'] == $job->user_id) ? $tr?->user_id : $job->user_id));

        $user = $tr?->user()?->first();

        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;

        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];

        (new AppMailer())->send($user?->email, $user?->name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = date('Y-m-d H:i:s');
        $tr->completed_by = $post_data['userid'];
        $tr->save();
    }//.... jobEnd() ....//

    /**
     * Function to get all Potential jobs of user with his ID
     * @param $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {
        $user_meta = UserMeta::where('user_id', $user_id)->first();

        $job_type = 'unpaid';

        if ($user_meta->translator_type == 'professional')
            $job_type = 'paid';   /*show all jobs for professionals.*/
        else if ($user_meta->translator_type == 'rwstranslator')
            $job_type = 'rws';  /* for rwstranslator only show rws jobs. */

        $languages = UserLanguages::where('user_id', '=', $user_id)->pluck('lang_id')->toArray();
        $job_ids = Job::getJobs($user_id, $job_type, 'pending', $languages, $user_meta->gender, $user_meta->translator_level);

        foreach ($job_ids as $k => $v) {
            $job = Job::find($v->id);
            $checktown = Job::checkTowns($job->user_id, $user_id);

            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($job_ids[$k]);
            }//..... end if() ....//
        }//..... end foreach() ....//

        return TeHelper::convertJobIdsInObjs($job_ids);
    }//..... end of getPotentialJobIdsWithUserId() .....//

    /**
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    {
        $translator_array = array();            // suitable translators (no need to delay push)
        $delpay_translator_array = array();     // suitable translators (need to delay push)

        foreach (User::cursor() as $oneUser) {
            if ($oneUser->user_type == '2' && $oneUser->status == '1' && $oneUser->id != $exclude_user_id) { // user is translator and he is not disabled

                if (!$this->isNeedToSendPush($oneUser->id)) continue;

                $not_get_emergency = TeHelper::getUsermeta($oneUser->id, 'not_get_emergency');

                if ($data['immediate'] == 'yes' && $not_get_emergency == 'yes') continue;

                $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id); // get all potential jobs of this user

                foreach ($jobs as $oneJob) {
                    if ($job->id == $oneJob->id) { // one potential job is the same with current job
                        $userId = $oneUser->id;
                        $job_for_translator = Job::assignedToPaticularTranslator($userId, $oneJob->id);
                        if ($job_for_translator == 'SpecificJob') {
                            $job_checker = Job::checkParticularJob($userId, $oneJob);
                            if (($job_checker != 'userCanNotAcceptJob')) {
                                if ($this->isNeedToDelayPush($oneUser->id)) {
                                    $delpay_translator_array[] = $oneUser;
                                } else {
                                    $translator_array[] = $oneUser;
                                }//..... end if-else() .....//
                            }//..... end if() .....//
                        }//..... end if() .....//
                    }//..... end if() .....//
                }//..... end of foreach() .....//
            }//..... end if() .....//
        }//..... end of foreach() .....//

        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';

        if ($data['immediate'] == 'no') {
            $msg_contents = 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due'];
        } else {
            $msg_contents = 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';
        }

        $msg_text = array(
            "en" => $msg_contents
        );

        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translator_array, $delpay_translator_array, $msg_text, $data]);
        $this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msg_text, false);       // send new booking push to suitable translators(not delay)
        $this->sendPushNotificationToSpecificUsers($delpay_translator_array, $job->id, $data, $msg_text, true); // send new booking push to suitable translators(need to delay)
    }

    /**
     * Sends SMS to translators and retuns count of translators
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        // prepare message templates
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ? $job->city : $jobPosterMeta->city;

        $phoneJobMessageTemplate = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);

        $physicalJobMessageTemplate = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);

        // analyse weather it's phone or physical; if both = default to phone
        if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') {
            // It's a physical job
            $message = $physicalJobMessageTemplate;
        } else if ($job->customer_physical_type == 'no' && $job->customer_phone_type == 'yes') {
            // It's a phone job
            $message = $phoneJobMessageTemplate;
        } else if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'yes') {
            // It's both, but should be handled as phone job
            $message = $phoneJobMessageTemplate;
        } else {
            // This shouldn't be feasible, so no handling of this edge case
            $message = '';
        }//..... end of if-else() .....//

        Log::info($message);

        // send messages via sms handler
        foreach ($translators as $translator) {
            // send message to translator
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }//..... end foreach() ....//

        return count($translators);
    }//..... end of sendSMSNotificationToTranslator() .....//

    /**
     * Function to delay the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToDelayPush($user_id)
    {
        if ( !DateTimeHelper::isNightTime()) return false;

        $not_get_nighttime = TeHelper::getUsermeta($user_id, 'not_get_nighttime');

        if ($not_get_nighttime == 'yes') return true;

        return false;
    }//..... end of isNeedToDelayPush() .....//

    /**
     * Function to check if need to send the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id)
    {
        $not_get_notification = TeHelper::getUsermeta($user_id, 'not_get_notification');

        if ($not_get_notification == 'yes') return false;

        return true;
    }//..... end of isNeedToSendPush() .....//

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $job_id
     * @param $data
     * @param $msg_text
     * @param $is_need_delay
     */
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {
        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);

        if (env('APP_ENV') == 'prod') {
            $onesignalAppID = config('app.prodOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.prodOnesignalApiKey'));
        } else {
            $onesignalAppID = config('app.devOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.devOnesignalApiKey'));
        }//..... end if-else() .....//

        $user_tags = $this->getUserTagsStringFromArray($users);

        $data['job_id'] = $job_id;
        $ios_sound = 'default';
        $android_sound = 'default';

        if ($data['notification_type'] == 'suitable_job') {
            if ($data['immediate'] == 'no') {
                $android_sound = 'normal_booking';
                $ios_sound = 'normal_booking.mp3';
            } else {
                $android_sound = 'emergency_booking';
                $ios_sound = 'emergency_booking.mp3';
            }//..... end if-else() .....//
        }//..... end if() .....//

        $fields = [
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($user_tags),
            'data'           => $data,
            'title'          => array('en' => 'DigitalTolk'),
            'contents'       => $msg_text,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $android_sound,
            'ios_sound'      => $ios_sound
        ];

        if ($is_need_delay) {
            $next_business_time = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $next_business_time;
        }//..... end if() .....//

        $fields = json_encode($fields);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $onesignalRestAuthKey));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);
        curl_close($ch);
    }//..... end of sendPushNotificationToSpecificUsers() .....//

    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {
        if ($job->job_type == 'paid')
            $translator_type = 'professional';
        else if ($job->job_type == 'rws')
            $translator_type = 'rwstranslator';
        else if ($job->job_type == 'unpaid')
            $translator_type = 'volunteer';

        $translator_level = [];

        if (!empty($job->certified)) {
            if ($job->certified == 'yes' || $job->certified == 'both') {
                $translator_level[] = 'Certified';
                $translator_level[] = 'Certified with specialisation in law';
                $translator_level[] = 'Certified with specialisation in health care';
            } elseif($job->certified == 'law' || $job->certified == 'n_law') {
                $translator_level[] = 'Certified with specialisation in law';
            } elseif($job->certified == 'health' || $job->certified == 'n_health') {
                $translator_level[] = 'Certified with specialisation in health care';
            } else if ($job->certified == 'normal' || $job->certified == 'both') {
                $translator_level[] = 'Layman';
                $translator_level[] = 'Read Translation courses';
            } elseif ($job->certified == null) {
                $translator_level[] = 'Certified';
                $translator_level[] = 'Certified with specialisation in law';
                $translator_level[] = 'Certified with specialisation in health care';
                $translator_level[] = 'Layman';
                $translator_level[] = 'Read Translation courses';
            }//..... end of if-else() .....//
        }//..... end if() .....//

        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();
        $translatorsId = collect($blacklist)->pluck('translator_id')->all();

        return User::getPotentialUsers($translator_type, $job->from_language_id, $job->gender, $translator_level, $translatorsId);
    }//..... end of getPotentialTranslators() .....//

    /**
     * @param $id
     * @param $data
     * @return mixed
     */
    public function updateJob($id, $data, $cuser)
    {
        $job = Job::findOrFail($id);
        $current_translator = $job->translatorJobRel->where('cancel_at', Null)->first();

        if (is_null($current_translator))
            $current_translator = $job->translatorJobRel->where('completed_at', '!=', Null)->first();

        $log_data = [];

        $langChanged = false;

        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);

        if ($changeTranslator['translatorChanged']) $log_data[] = $changeTranslator['log_data'];

        $changeDue = $this->changeDue($job->due, $data['due']);

        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }//..... end if() ....//

        if ($job->from_language_id != $data['from_language_id']) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];

            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }//..... end if() ....//

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);

        if ($changeStatus['statusChanged'])
            $log_data[] = $changeStatus['log_data'];

        $job->admin_comments = $data['admin_comments'];

        $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $log_data);

        $job->reference = $data['reference'];

        if ($job->due <= Carbon::now()) {
            $job->save();
            return ['Updated'];
        } else {
            $job->save();
            if ($changeDue['dateChanged']) $this->sendChangedDateNotification($job, $old_time);
            if ($changeTranslator['translatorChanged']) $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
            if ($langChanged) $this->sendChangedLangNotification($job, $old_lang);
        }//..... end if-else() ....//
    }//..... end of updateJob() .....//

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
        if ($job->status != $data['status']) {
            switch ($job->status) {
                case 'timedout':
                    $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                    break;
                case 'completed':
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                    break;
                case 'withdrawafter24':
                    $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                    break;
                case 'assigned':
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    $statusChanged = false;
                    break;
            }

            if ($statusChanged) {
                $log_data = [
                    'old_status' => $job->status,
                    'new_status' => $data['status']
                ];

                return ['statusChanged' => true, 'log_data' => $log_data];
            }//..... end if() .....//
        }//..... end if() .....//
    }//..... end of changeStatus() .....//

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];
        $user = $job->user()->first();

        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }//..... end if-else() .....//

        $name = $user->name;

        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] == 'pending') {
            $job->created_at = date('Y-m-d H:i:s');
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $job_data = $this->jobToData($job);
            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);
            $this->sendNotificationTranslator($job, $job_data, '*');   // send Push all sutiable translators

            return true;
        } elseif ($changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            return true;
        }//..... end if-else() .....//

        return false;
    }//..... end of changeTimedoutStatus() .....//

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data)
    {
        $job->status = $data['status'];

        if ($data['status'] == 'timedout') {
            if ($data['admin_comments'] == '') return false;

            $job->admin_comments = $data['admin_comments'];
        }//..... end if() .....//

        $job->save();

        return true;
    }//..... end of changeCompletedStatus() .....//

    /**
     * @param $job
     * @param $data
     * @return bool
     * Change started status of a job.
     */
    private function changeStartedStatus($job, $data)
    {
        $job->status = $data['status'];

        if ($data['admin_comments'] == '') return false;

        $job->admin_comments = $data['admin_comments'];

        if ($data['status'] == 'completed') {
            $user = $job->user()->first();

            if ($data['sesion_time'] == '') return false;

            $diff = explode(':', $data['sesion_time']);
            $job->end_at = date('Y-m-d H:i:s');
            $job->session_time = $data['sesion_time'];
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';

            $email =  !empty($job->user_email) ? $job->user_email : $user->email;

            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'faktura'
            ];

            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $user->name, $subject, 'emails.session-ended', $dataEmail);

            $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;

            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'lön'
            ];

            $this->mailer->send($user?->user?->email, $user?->user?->name, $subject, 'emails.session-ended', $dataEmail);
        }//..... end if() .....//

        $job->save();

        return true;
    }//..... end of changeStartedStatus() .....//

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     * Change pending status of a job.
     */
    private function changePendingStatus($job, $data, $changedTranslator): bool
    {
        $job->status = $data['status'];

        if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;

        $job->admin_comments = $data['admin_comments'];
        $user = $job->user()->first();

        $email = !empty($job->user_email) ? $job->user_email : $user->email;

        $dataEmail = [ 'user' => $user, 'job'  => $job ];

        if ($data['status'] == 'assigned' && $changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $user->name, $subject, 'emails.job-accepted', $dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
            return true;
        } else {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $user->name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
            $job->save();
            return true;
        }//..... end if-else() .....//

        return false;
    }//..... end of changePendingStatus() .....//

    /**
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     **/
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration): void
    {
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());

        $data = ['notification_type' => 'session_start_remind'];
        $due_explode = explode(' ', $due);

        if ($job->customer_physical_type == 'yes')
            $msg_text = [
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            ];
        else
            $msg_text = [
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            ];

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $this->bookingRepository->sendPushNotificationToSpecificUsers([$user], $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }//.... end if() .....//
    }//..... end of sendSessionStartRemindNotification() .....//

    /**
     * @param $job
     * @param $data
     * @return bool
     * Change withdraw status after 24 hours.
     */
    private function changeWithdrawafter24Status($job, $data): bool
    {
        if (in_array($data['status'], ['timedout'])) {
            $job->status = $data['status'];

            if ($data['admin_comments'] == '') return false;

            $job->admin_comments = $data['admin_comments'];
            $job->save();

            return true;
        }//..... end if() ....//

        return false;
    }//..... end of changeWithdrawafter24Status() .....//

    /**
     * @param $job
     * @param $data
     * @return bool
     * Change Assigned status.
     */
    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];

            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;

            $job->admin_comments = $data['admin_comments'];

            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();

                $email = (!empty($job->user_email)) ? $job->user_email : $user->email;
                $dataEmail = ['user' => $user, 'job'  => $job];
                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($email, $user->name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

                $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

                $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
                $dataEmail = ['user' => $user, 'job'  => $job];

                $this->mailer->send($user?->user?->email, $user?->user?->name, $subject, 'emails.job-cancel-translator', $dataEmail);
            }//..... end if() .....//

            $job->save();

            return true;
        }//..... end if() .....//

        return false;
    }//..... end of changeAssignedStatus() .....//

    /**
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, $job): array
    {
        $translatorChanged = false;

        if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
            $log_data = [];
            if (!is_null($current_translator) && ((isset($data['translator']) && $current_translator->user_id != $data['translator']) || $data['translator_email'] != '') && (isset($data['translator']) && $data['translator'] != 0)) {

                if ($data['translator_email'] != '')
                    $data['translator'] = User::where('email', $data['translator_email'])->first()->id;

                $new_translator = $current_translator->toArray();
                $new_translator['user_id'] = $data['translator'];
                unset($new_translator['id']);

                $new_translator = Translator::create($new_translator);
                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();

                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];

                $translatorChanged = true;
            } elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                if ($data['translator_email'] != '')
                    $data['translator'] = User::where('email', $data['translator_email'])->first()->id;

                $new_translator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);

                $log_data[] = ['old_translator' => null, 'new_translator' => $new_translator->user->email];

                $translatorChanged = true;
            }//..... end if-else() .....//

            if ($translatorChanged)
                return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];
        }//..... end if() .....//

        return ['translatorChanged' => $translatorChanged];
    }//..... end of changeTranslator() .....//

    /**
     * @param $old_due
     * @param $new_due
     * @return array
     * change the due date
     */
    private function changeDue($old_due, $new_due): array
    {
        if ($old_due != $new_due)
            return ['dateChanged' => true, 'log_data' => ['old_due' => $old_due, 'new_due' => $new_due]];

        return ['dateChanged' => false];
    }//..... end of changeDue() .....//

    /**
     * @param $job
     * @param $current_translator
     * @param $new_translator
     * Send change of translator notification
     */
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator): void
    {
        $user = $job->user()->first();

        $email = (!empty($job->user_email)) ? $job->user_email : $user->email;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
        $data = [ 'user' => $user, 'job'  => $job ];

        $this->mailer->send($email, $user->name, $subject, 'emails.job-changed-translator-customer', $data);

        if ($current_translator) {
            $data['user'] = $current_translator->user;
            $this->mailer->send($current_translator?->user?->email, $current_translator?->user?->name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }//..... end if() .....//

        $data['user'] = $new_translator->user;

        $this->mailer->send($new_translator?->user?->email, $new_translator?->user?->name, $subject, 'emails.job-changed-translator-new-translator', $data);
    }//..... end of sendChangedTranslatorNotification() .....//

    /**
     * @param $job
     * @param $old_time
     * send notification for changing the date.
     */
    public function sendChangedDateNotification($job, $old_time): void
    {
        $user = $job->user()->first();

        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = ['user' => $user, 'job' => $job, 'old_time' => $old_time];

        $this->mailer->send( $job->user_email ?? $user->email, $user?->name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);

        $data = ['user' => $translator, 'job' => $job, 'old_time' => $old_time];

        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }//..... end of sendChangedDateNotification() .....//

    /**
     * @param $job
     * @param $old_lang
     * Send change language notification.
     */
    public function sendChangedLangNotification($job, $old_lang): void
    {
        $user = $job->user()->first();
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];

        $this->mailer->send($job->user_email ?? $user->email, $user->name, $subject, 'emails.job-changed-lang', $data);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }//..... end of sendChangedLangNotification() .....//

    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user): void
    {
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

        $msg_text = [
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        ];

        if ($this->isNeedToSendPush($user->id)) {
            $this->sendPushNotificationToSpecificUsers([$user], $job->id, ['notification_type' => 'job_expired'], $msg_text, $this->isNeedToDelayPush($user->id));
        }//..... end if() .....//
    }//..... end of sendExpiredNotification() ......//

    /**
     * Function to send the notification for sending the admin job cancel
     * @param $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id): void
    {
        $job = Job::findOrFail($job_id);
        $user_meta = $job->user->userMeta()->first();

        $data = $this->jobToData($job);

        $data['customer_town'] = $user_meta->city;
        $data['customer_type'] = $user_meta->customer_typ;

        $this->sendNotificationTranslator($job, $data, '*');   // send Push all sutiable translators
    }//..... end of sendNotificationByAdminCancelJob() .....//

    /**
     * send session start remind notification
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        if ($job->customer_physical_type == 'yes')
            $msg_text = [
                "en" => 'Du har nu fått platstolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            ];
        else
            $msg_text = [
                "en" => 'Du har nu fått telefontolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            ];

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $this->bookingRepository->sendPushNotificationToSpecificUsers([$user], $job->id, ['notification_type' => 'session_start_remind'], $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
        }//..... end if() .....//
    }//..... end of sendNotificationChangePending() .....//

    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users): string
    {
        $user_tags = "[";
        $first = true;

        foreach ($users as $oneUser) {
            if ($first) {
                $first = false;
            } else {
                $user_tags .= ',{"operator": "OR"},';
            }//..... end if-else() .....//

            $user_tags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }//..... end foreach() .....//

        $user_tags .= ']';

        return $user_tags;
    }//..... end of getUserTagsStringFromArray() .....//

    /**
     * @param $user
     * @param $job_id
     *  Accept the job.
     */
    public function acceptJob($user, $job_id): array
    {
        $job = Job::findOrFail($job_id);

        if (Job::isTranslatorAlreadyBooked($job_id, $user->id, $job->due))
            return ['status' => 'fail', 'message' => 'Du har redan en bokning den tiden! Bokningen är inte accepterad.'];

        if ($job->status == 'pending' && Job::insertTranslatorJobRel($user->id, $job_id)) {
            $job->status = 'assigned';
            $job->save();

            $user = $job->user()->first();

            if (!empty($job->user_email)) {
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            } else {
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            }//..... end if-else() .....//

            (new AppMailer())->send($job->user_email ?? $user->email, $user->name, $subject, 'emails.job-accepted', [ 'user' => $user, 'job'  => $job ]);
        }

        $jobs = $this->getPotentialJobs($user);

        return [
            'list'   => json_encode(['jobs' => $jobs, 'job' => $job], true),
            'status' => 'success'
        ];
    }//..... end of acceptJob() .....//

    /**
     * @param $job_id
     * @param $cuser
     * @return array
     * Accept the job with id.
     */
    public function acceptJobWithId($job_id, $cuser): array
    {
        $job = Job::findOrFail($job_id);

        if (Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due))
            return ['status' => 'fail', 'message' => 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning'];

        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

        if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
            $job->status = 'assigned';
            $job->save();

            $user = $job->user()->get()->first();

            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';

            (new AppMailer())->send($job->user_email ?? $user->email, $user->name, $subject, 'emails.job-accepted', ['user' => $user, 'job'  => $job ]);

            $msg_text = [
                "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
            ];

            if ($this->isNeedToSendPush($user->id)) {
                $this->sendPushNotificationToSpecificUsers([$user], $job_id, ['notification_type' => 'job_accepted'], $msg_text, $this->isNeedToDelayPush($user->id));
            }

            return [
                'status'    => 'success',
                'list'      => ['job' => $job],
                'message'   => 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due
            ];
        } else {
            return [
                'status' => 'fail',
                'message'=> 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning'
            ];
        }//..... end if-else() .....//
    }//.... end of acceptJobWithId() .....//

    /**
     * Cancel the job.
     * add 24hrs loging here.
     *If the cancelation is before 24 hours before the booking tie - supplier will be informed. Flow ended
     *if the cancelation is within 24
     *if cancelation is within 24 hours - translator will be informed AND the customer will get an addition to his number of bookings - so we will charge of it if the cancelation is within 24 hours
     *so we must treat it as if it was an executed session
     **/
    public function cancelJob($job_id, $user): array
    {
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);

        if ($user->isCustomer()) {
            $job->withdraw_at = Carbon::now();
            $job->status = ($job->withdraw_at->diffInHours($job->due) >= 24) ? 'withdrawbefore24': 'withdrawafter24';
            $job->save();

            Event::fire(new JobWasCanceled($job));

            if ($translator and $this->isNeedToSendPush($translator->id)) {
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = [
                    "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                ];

                $this->sendPushNotificationToSpecificUsers([$translator], $job_id, ['notification_type' => 'job_cancelled'],
                    $msg_text, $this->isNeedToDelayPush($translator->id));// send Session Cancel Push to Translaotor
            }//.... end if() .....//

            return ['status' => 'success', 'jobstatus' => 'success'];
        } else {
            if ($job->due->diffInHours(Carbon::now()) > 24) {
                $customer = $job->user()->get()->first();

                if ($customer and $this->isNeedToSendPush($customer->id)) {
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msg_text = [
                        "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                    ];

                    $this->sendPushNotificationToSpecificUsers([$customer], $job_id, ['notification_type' => 'job_cancelled'], $msg_text, $this->isNeedToDelayPush($customer->id));     // send Session Cancel Push to customer
                }//..... end if() ....//

                $job->update([
                    'status'        => 'pending',
                    'created_at'    => date('Y-m-d H:i:s'),
                    'will_expire_at'=> TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'))
                ]);

                Job::deleteTranslatorJobRel($translator->id, $job_id);

                $data = $this->jobToData($job);

                $this->sendNotificationTranslator($job, $data, $translator->id);   // send Push all sutiable translators

                return ['status' => 'success', 'jobstatus' => 'success'];
            } else {
                return [
                    'status' => 'fail',
                    'message' => 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!'
                ];
            }//.... end if() .....//
        }//.... end if-else() .....//
    }//..... end of cancelJob() ....//

    /**
    * Function to get the potential jobs for paid,rws,unpaid translators
     */
    public function getPotentialJobs($cuser)
    {
        $job_type = match ($cuser->userMeta->translator_type) {
            'professional'  => 'paid',
            'rwstranslator' => 'rws',
            default         => 'unpaid'
        };

        $userLanguages = UserLanguages::where('user_id', '=', $cuser->id)->pluck('lang_id');

        /*Call the town function for checking if the job physical, then translators in one town can get job*/
        $job_ids = Job::getJobs($cuser->id, $job_type, 'pending', $userLanguages, $cuser->userMeta->gender, $cuser->userMeta->translator_level);

        foreach ($job_ids as $k => $job) {
            $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);
            $checktown = Job::checkTowns($job->user_id, $cuser->id);

            if($job->specific_job == 'SpecificJob')
                if ($job->check_particular_job == 'userCanNotAcceptJob')
                unset($job_ids[$k]);

            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($job_ids[$k]);
            }
        }//..... end of foreach() .....//

        return $job_ids;
    }//..... end of getPotentialJobs() .....//

    /**
     * @param $job_id
     * @param $user_id
     * @return string[]
     * End the specific user job.
     */
    public function endJob($job_id, $user_id): array
    {
        $job = Job::with('translatorJobRel')->findOrFail($job_id);

        if($job->status != 'started')
            return ['status' => 'success'];

        $diff = date_diff(date_create(date('Y-m-d H:i:s')), date_create($job->due));
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $diff->h . ':' . $diff->i . ':' . $diff->s;

        $user = $job->user()->get()->first();

        $email = (!empty($job->user_email)) ? $job->user_email: $user->email;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = ['user' => $user, 'job' => $job, 'session_time' => $session_time, 'for_text' => 'faktura'];

        (new AppMailer())->send($email, $user->name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($job, ($user_id == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = ['user' => $user, 'job' => $job, 'session_time' => $session_time, 'for_text' => 'lön'];

        (new AppMailer())->send($user->email, $user->name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = date('Y-m-d H:i:s');
        $tr->completed_by = $user_id;
        $tr->save();

        return ['status' => 'success'];
    }//..... end of endJob() .....//

    /**
     * @param $job_id
     * @return string[]
     */
    public function customerNotCall($job_id): array
    {
        $job = Job::with('translatorJobRel')->findOrFail($job_id);
        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->firstOrFail();
        $tr->update(['completed_at' => now()->format("Y-m-d H:i:s"), 'completed_by' => $tr->user_id]);
        $job->update(['end_at' => now()->format("Y-m-d H:i:s"), 'status' => 'not_carried_out_customer']);

        return ['status' => 'success'];
    }//..... end of customerNotCall() ..../

    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumer_type = $cuser->consumer_type;

        if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID')) {
            $allJobs = Job::query();

            if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0');
                $allJobs->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });

                if (isset($requestdata['count']) && $requestdata['count'] != 'false') return ['count' => $allJobs->count()];
            }

            if (isset($requestdata['id']) && $requestdata['id'] != '') {
                if (is_array($requestdata['id']))
                    $allJobs->whereIn('id', $requestdata['id']);
                else
                    $allJobs->where('id', $requestdata['id']);
                $requestdata = array_only($requestdata, ['id']);
            }

            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('from_language_id', $requestdata['lang']);
            }

            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('status', $requestdata['status']);
            }

            if (isset($requestdata['expired_at']) && $requestdata['expired_at'] != '') {
                $allJobs->where('expired_at', '>=', $requestdata['expired_at']);
            }

            if (isset($requestdata['will_expire_at']) && $requestdata['will_expire_at'] != '') {
                $allJobs->where('will_expire_at', '>=', $requestdata['will_expire_at']);
            }

            if (isset($requestdata['customer_email']) && count($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $users = DB::table('users')->whereIn('email', $requestdata['customer_email'])->get();

                if ($users) {
                    $allJobs->whereIn('user_id', collect($users)->pluck('id')->all());
                }
            }

            if (isset($requestdata['translator_email']) && count($requestdata['translator_email'])) {
                $users = DB::table('users')->whereIn('email', $requestdata['translator_email'])->get();

                if ($users) {
                    $allJobIDs = DB::table('translator_job_rel')->whereNull('cancel_at')->whereIn('user_id', collect($users)->pluck('id')->all())->lists('job_id');
                    $allJobs->whereIn('id', $allJobIDs);
                }
            }

            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('created_at', '>=', $requestdata["from"]);
                }

                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('created_at', '<=', $to);
                }

                $allJobs->orderBy('created_at', 'desc');
            }

            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('due', '>=', $requestdata["from"]);
                }

                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('due', '<=', $to);
                }

                $allJobs->orderBy('due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('job_type', $requestdata['job_type']);
            }

            if (isset($requestdata['physical'])) {
                $allJobs->where('customer_physical_type', $requestdata['physical']);
                $allJobs->where('ignore_physical', 0);
            }

            if (isset($requestdata['phone'])) {
                $allJobs->where('customer_phone_type', $requestdata['phone']);

                if(isset($requestdata['physical']))
                    $allJobs->where('ignore_physical_phone', 0);
            }

            if (isset($requestdata['flagged'])) {
                $allJobs->where('flagged', $requestdata['flagged']);
                $allJobs->where('ignore_flagged', 0);
            }

            if (isset($requestdata['distance']) && $requestdata['distance'] == 'empty') {
                $allJobs->whereDoesntHave('distance');
            }

            if(isset($requestdata['salary']) &&  $requestdata['salary'] == 'yes') {
                $allJobs->whereDoesntHave('user.salaries');
            }

            if (isset($requestdata['count']) && $requestdata['count'] == 'true') {
                $allJobs = $allJobs->count();

                return ['count' => $allJobs];
            }

            if (isset($requestdata['consumer_type']) && $requestdata['consumer_type'] != '') {
                $allJobs->whereHas('user.userMeta', function($q) use ($requestdata) {
                    $q->where('consumer_type', $requestdata['consumer_type']);
                });
            }

            if (isset($requestdata['booking_type'])) {
                if ($requestdata['booking_type'] == 'physical')
                    $allJobs->where('customer_physical_type', 'yes');

                if ($requestdata['booking_type'] == 'phone')
                    $allJobs->where('customer_phone_type', 'yes');
            }
            
            $allJobs->orderBy('created_at', 'desc');
            $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');

            if ($limit == 'all')
                $allJobs = $allJobs->get();
            else
                $allJobs = $allJobs->paginate(15);

        } else {

            $allJobs = Job::query();

            if (isset($requestdata['id']) && $requestdata['id'] != '') {
                $allJobs->where('id', $requestdata['id']);
                $requestdata = array_only($requestdata, ['id']);
            }

            if ($consumer_type == 'RWS') {
                $allJobs->where('job_type', '=', 'rws');
            } else {
                $allJobs->where('job_type', '=', 'unpaid');
            }

            if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
                $allJobs->where('ignore_feedback', '0');
                $allJobs->whereHas('feedback', function($q) {
                    $q->where('rating', '<=', '3');
                });

                if(isset($requestdata['count']) && $requestdata['count'] != 'false') return ['count' => $allJobs->count()];
            }
            
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('from_language_id', $requestdata['lang']);
            }

            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('status', $requestdata['status']);
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('job_type', $requestdata['job_type']);
            }

            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();

                if ($user) {
                    $allJobs->where('user_id', '=', $user->id);
                }
            }

            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('created_at', '>=', $requestdata["from"]);
                }

                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('created_at', '<=', $to);
                }

                $allJobs->orderBy('created_at', 'desc');
            }

            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('due', '>=', $requestdata["from"]);
                }

                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('due', '<=', $to);
                }

                $allJobs->orderBy('due', 'desc');
            }

            $allJobs->orderBy('created_at', 'desc');
            $allJobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');

            if ($limit == 'all')
                $allJobs = $allJobs->get();
            else
                $allJobs = $allJobs->paginate(15);

        }

        return $allJobs;
    }

    public function alerts()
    {
        $jobId = [];

        foreach (Job::cursor() as $job) {
            $sessionTime = explode(':', $job->session_time);

            if (count($sessionTime) >= 3) {
                $diff = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

                if ($diff >= $job->duration) {
                    if ($diff >= $job->duration * 2) {
                        $jobId[] = $job->id;
                    }
                }
            }
        }

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();

        if ($cuser && $cuser->is('superadmin')) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')->whereIn('jobs.id', $jobId);
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }

            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }

            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();

                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.ignore', 0);
                }
            }

            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();

                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.ignore', 0);
                }
            }

            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }

                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.ignore', 0);
                }

                $allJobs->orderBy('jobs.created_at', 'desc');
            }

            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }

                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.ignore', 0);
                }

                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.ignore', 0);
            }

            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.ignore', 0)
                ->whereIn('jobs.id', $jobId);

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }

    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();

        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0);

            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }

            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }

            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }

            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }
            }

            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }

                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }

                $allJobs->orderBy('jobs.created_at', 'desc');
            }

            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }

                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.status', 'pending')
                        ->where('jobs.ignore_expired', 0)
                        ->where('jobs.due', '>=', Carbon::now());
                }

                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.status', 'pending')
                    ->where('jobs.ignore_expired', 0)
                    ->where('jobs.due', '>=', Carbon::now());
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }

            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.status', 'pending')
                ->where('ignore_expired', 0)
                ->where('jobs.due', '>=', Carbon::now());

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);

        }

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    public function ignoreExpiring($id)
    {
        $job = Job::find($id);
        $job->ignore = 1;
        $job->save();

        return ['success', 'Changes saved'];
    }

    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();

        return ['success', 'Changes saved'];
    }

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();

        return ['success', 'Changes saved'];
    }

    /**
     * @param $jobid
     * @param $userid
     * @return string[]
     * ReOpen the job.
     */
    public function reopen($jobid, $userid): array
    {
        $job = Job::findOrFail($jobid);

        $data = [
            'created_at'     => date('Y-m-d H:i:s'),
            'will_expire_at' => TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s')),
            'updated_at'     => date('Y-m-d H:i:s'),
            'user_id'        => $userid,
            'job_id'         => $jobid,
            'cancel_at'      => Carbon::now()
        ];

        if ($job['status'] != 'timedout') {
            $affectedRow = Job::where('id', '=', $jobid)->update([
                'status'         => 'pending',
                'created_at'     => Carbon::now(),
                'will_expire_at' => TeHelper::willExpireAt($job['due'], now())
            ]);

            $new_jobid = $jobid;
        } else {
            $affectedRow = Job::create(['status' => 'pending',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'will_expire_at' => TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s')),
                'cust_16_hour_email' => 0,
                'cust_48_hour_email' => 0,
                'admin_comments' => 'This booking is a reopening of booking #' . $jobid
            ]);

            $new_jobid = $affectedRow->id;
        }//..... end if-else() .....//

        Translator::where('job_id', $jobid)->where('cancel_at', NULL)->update(['cancel_at' => now()]);
        Translator::create($data);

        if (isset($affectedRow)) {
            $this->sendNotificationByAdminCancelJob($new_jobid);
            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }//.... end of if-else() ....//
    }//..... end of reopen() .....//

    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time   
     * @param  string $format 
     * @return string         
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);
        
        return sprintf($format, $hours, $minutes);
    }
}