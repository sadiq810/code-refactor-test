<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Models\Distance;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{
    /**
     * @var BookingRepository
     */
    protected $repository;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }//..... end of __construct() .....//

    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        if (! $request->user_id || !in_array($request->__authenticatedUser->user_type, [env('ADMIN_ROLE_ID'), env('SUPERADMIN_ROLE_ID')]))
            return null;

        if ($request->user_id)
            return $this->repository->getUsersJobs($request->user_id);

        return $this->repository->getAll($request);
    }//..... end of index() .....//

    /**
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        return $this->repository->with('translatorJobRel.user')->findOrFail($id);
    }//..... end of show() .....//

    /**
     * @param Request $request
     * @return mixed
     */
    public function store(Request $request): array
    {
        $validator = Validator::make($request->all(), [/*...*/]);

        if ($validator->fails()) {
            return [];//return error messages or custom.
        }

        return $this->repository->store($request->__authenticatedUser, $request->all());
    }//..... end of store() .....//

    /**
     * @param $id
     * @param Request $request
     * @return mixed
     */
    public function update($id, Request $request): array
    {
        $validator = Validator::make($request->all(), [/*...*/]);

        if ($validator->fails()) {
            return [];//return error messages or custom.
        }

        return $this->repository->updateJob($id, $request->except(['_token', 'submit']), $request->__authenticatedUser);
    }//..... end of update() .....//

    /**
     * @param Request $request
     * @return mixed
     */
    public function immediateJobEmail(Request $request): array
    {
        $validator = Validator::make($request->all(), [/*...*/]);

        if ($validator->fails())
            return [];//return error messages or custom.

        return $this->repository->storeJobEmail($request->all());
    }//..... end of immediateJobEmail() .....//

    /**
     * @param Request $request
     * @return mixed
     * Get user jobs history.
     */
    public function getHistory(Request $request): array | null
    {
        if (! $request->user_id)
            return null;

        return $this->repository->getUsersJobsHistory($request);
    }//..... end of getHistory() .....//

    /**
     * @param Request $request
     * @return mixed
     * Accept job.
     */
    public function acceptJob(Request $request): array | null
    {
        if (! $request->__authenticatedUser)
            return null;// or may be a specific status and message should be returned.

        return $this->repository->acceptJob($request->__authenticatedUser, $request->job_id);
    }//..... end of acceptJob() .....//

    /**
     * @param Request $request
     * @return mixed
     * Accept the job.
     */
    public function acceptJobWithId(Request $request): array
    {
        if ($request->job_id and $request->__authenticatedUser)
            return $this->repository->acceptJobWithId($request->job_id, $request->__authenticatedUser);

        return ['status' => 'fail', 'message' => 'Some of the parameters are missing'];
    }//..... end of acceptJobWithId() .....//

    /**
     * @param Request $request
     * @return mixed
     * Cancel the job by id.
     */
    public function cancelJob(Request $request): array
    {
        if ($request->job_id and $request->__authenticatedUser)
            return $this->repository->cancelJob($request->job_id, $request->__authenticatedUser);

        return ['status' => 'fail', 'message' => 'Some of the parameters are missing'];
    }//..... end of cancelJob() .....//

    /**
     * @param Request $request
     * @return array
     * End the job.
     */
    public function endJob(Request $request): array
    {
        if (! ($request->job_id and $request->user_id))
            return ['status' => 'fail', 'message' => 'Some of the parameters are missing'];

        return $this->repository->endJob($request->job_id, $request->user_id);
    }//..... end of endJob() .....//

    /**
     * @param Request $request
     * @return mixed
     */
    public function customerNotCall(Request $request): array
    {
        if (! $request->job_id)
            return ['status' => 'fail', 'message' => 'Some of the parameters are missing'];

        return $this->repository->customerNotCall($request->job_id);
    }//..... end of customerNotCall() .....//

    /**
     * @param Request $request
     * @return mixed
     * Get potential Jobs.
     */
    public function getPotentialJobs(Request $request)
    {
        if (! $request->__authenticatedUser)
            return ['status' => 'fail', 'message' => 'Not authorized to perform this action.'];

        return $this->repository->getPotentialJobs($request->__authenticatedUser);
    }//..... end of getPotentialJobs() .....//

    /**
     * @param Request $request
     * @return string
     * Distance feed.
     */
    public function distanceFeed(Request $request)
    {
        $distance = (isset($request->distance) && $request->distance != "") ? $request->distance : "";
        $time     = (isset($request->time) && $request->time != "") ? $request->time : "";
        $session  = (isset($request->session_time) && $request->session_time != "") ? $request->session_time : "";

        if ($request->flagged == 'true') {
            if($request->admincomment == '')
                return "Please, add comment";
            $flagged = 'yes';
        } else
            $flagged = 'no';

        $manually_handled = $request->manually_handled == 'true' ? 'yes' : 'no';
        $by_admin = $request->by_admin == 'true' ? 'yes' : 'no';
        $admincomment = ($request->admincomment && $request->admincomment != "") ? $request->admincomment : "";

        if ($time || $distance)
            Distance::where('job_id', '=', $request->jobid)->update(['distance' => $distance, 'time' => $time]);

        if ($admincomment || $session || $flagged || $manually_handled || $by_admin)
            Job::where('id', '=', $request->jobid)
                ->update(['admin_comments' => $admincomment,      'flagged' => $flagged, 'session_time' => $session,
                        'manually_handled' => $manually_handled, 'by_admin' => $by_admin]);

        return response('Record updated!');
    }//..... end of distanceFeed() .....//

    /**
     * @param Request $request
     * @return string[]
     * ReOpen the job.
     */
    public function reopen(Request $request): array
    {
        return $this->repository->reopen($request->jobid, $request->userid);
    }//..... end of reopen() .....//

    /**
     * @param Request $request
     * @return string[]
     * ReSend the push notification.
     */
    public function resendNotifications(Request $request): array
    {
        $job = $this->repository->find($request->jobid);

        if (!$job)
            return ['status' => 'failed', 'message' => 'Job not found.'];

        $job_data = $this->repository->jobToData($job);
        $this->repository->sendNotificationTranslator($job, $job_data, '*');

        return ['success' => 'Push sent'];
    }//..... end of resendNotifications() .....//

    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendSMSNotifications(Request $request)
    {
        try {
            $job = $this->repository->find($request->jobid);
            $this->repository->sendSMSNotificationToTranslator($job);

            return response(['success' => 'SMS sent']);
        } catch (\Exception $e) {
            return response(['success' => $e->getMessage()]);
        }//..... end of try-catch() .....//
    }//..... end of resendSMSNotifications() ......//
}