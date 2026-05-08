<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\ContentSubmission;
use App\Models\ContentRevenue;
use App\Models\ContentAgreement;
use App\Services\ContentService;
use App\Services\BulkOperationsService;
use Core\Validator;
use App\Controllers\Admin\BaseAdminController;

class ContentController extends BaseAdminController
{
    private ContentSubmission $contentSubmissionModel;
    private ContentRevenue $contentRevenueModel;
    private ContentAgreement $contentAgreementModel;
    private ContentService $contentService;
    private BulkOperationsService $bulkService;

    public function __construct(
        ContentAgreement $contentAgreementModel,
        ContentRevenue $contentRevenueModel,
        ContentSubmission $contentSubmissionModel,
        ContentService $contentService,
        BulkOperationsService $bulkService
    ) {
        parent::__construct();
        $this->contentService = $contentService;
        $this->contentAgreementModel = $contentAgreementModel;
        $this->contentRevenueModel = $contentRevenueModel;
        $this->contentSubmissionModel = $contentSubmissionModel;
        $this->bulkService = $bulkService;
    }

    /**
     * لیست تمام محتواها
     */
    public function index()
    {
        $filters = [
            'status' => $_GET['status'] ?? null,
            'platform' => $_GET['platform'] ?? null,
            'search' => $_GET['search'] ?? null,
        ];

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 15;
        $offset = ($page - 1) * $perPage;

        $submissions = $this->contentSubmissionModel->getAll($filters, $perPage, $offset);
        $total = $this->contentSubmissionModel->countAll($filters);
        $totalPages = (int)ceil($total / $perPage);
        $stats = $this->contentSubmissionModel->getStats();

        return view('admin.content.index', [
            'user' => auth()->user(),
            'submissions' => $submissions,
            'stats' => $stats,
            'total' => $total,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'filters' => $filters,
        ]);
    }

    /**
     * مشاهده جزئیات محتوا
     */
    public function show()
    {
        $id = (int)$this->request->param('id');

        $submission = $this->contentSubmissionModel->findWithUser($id);

        if (!$submission) {
            return view('errors.404');
        }

        $revenues = $this->contentRevenueModel->getBySubmission($id);
        $agreement = $this->contentAgreementModel->findBySubmission($id);

        return view('admin.content.show', [
            'user' => auth()->user(),
            'submission' => $submission,
            'revenues' => $revenues,
            'agreement' => $agreement,
        ]);
    }

    /**
     * تأیید محتوا (AJAX)
     */
    public function approve()
    {
        $id = (int)$this->request->param('id');
        $result = $this->contentService->approveSubmission($id, user_id());

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * رد محتوا (AJAX)
     */
    public function reject()
    {
        $id = (int)$this->request->param('id');
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $validator = new Validator($input, [
            'reason' => 'required|min:10|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->response->json([
                'success' => false,
                'message' => 'لطفاً دلیل رد را وارد کنید (حداقل ۱۰ کاراکتر).',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->data();
        $result = $this->contentService->rejectSubmission($id, user_id(), $data['reason']);

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * ثبت انتشار (AJAX)
     */
    public function publish()
    {
        $id = (int)$this->request->param('id');
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $validator = new Validator($input, [
            'published_url' => 'url|max:500',
            'channel_name' => 'max:255',
        ]);

        if ($validator->fails()) {
            return $this->response->json([
                'success' => false,
                'message' => 'اطلاعات وارد شده نامعتبر است.',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->data();
        $result = $this->contentService->publishSubmission(
            $id, 
            user_id(), 
            $data['published_url'] ?? '', 
            $data['channel_name'] ?? ''
        );

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * تأیید گروهی (AJAX)
     */
    public function bulkApprove()
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        if (empty($input['ids']) || !is_array($input['ids'])) {
            return $this->response->json([
                'success' => false,
                'message' => 'هیچ محتوایی انتخاب نشده است.'
            ], 422);
        }

        $ids = array_map('intval', $input['ids']);
        
        $result = $this->bulkService->bulkUpdate(
            'content_submissions',
            $ids,
            [
                'status' => 'approved',
                'approved_at' => date('Y-m-d H:i:s'),
                'approved_by' => user_id()
            ]
        );

        return $this->response->json($result);
    }

    /**
     * رد گروهی (AJAX)
     */
    public function bulkReject()
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        if (empty($input['ids']) || !is_array($input['ids'])) {
            return $this->response->json([
                'success' => false,
                'message' => 'هیچ محتوایی انتخاب نشده است.'
            ], 422);
        }

        $validator = new Validator($input, [
            'reason' => 'required|min:10|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->response->json([
                'success' => false,
                'message' => 'لطفاً دلیل رد را وارد کنید (حداقل ۱۰ کاراکتر).',
            ], 422);
        }

        $data = $validator->data();
        $ids = array_map('intval', $input['ids']);
        
        $result = $this->bulkService->bulkUpdate(
            'content_submissions',
            $ids,
            [
                'status' => 'rejected',
                'rejection_reason' => $data['reason'],
                'rejected_at' => date('Y-m-d H:i:s'),
                'rejected_by' => user_id()
            ]
        );

        return $this->response->json($result);
    }

    /**
     * صادرات محتواها به CSV
     */
    public function export()
    {
        $filters = [
            'status' => $_GET['status'] ?? null,
            'platform' => $_GET['platform'] ?? null,
            'search' => $_GET['search'] ?? null,
        ];

        // ساخت کوئری
        $where = ['cs.is_deleted = 0'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'cs.status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['platform'])) {
            $where[] = 'cs.platform = ?';
            $params[] = $filters['platform'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(cs.title LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)';
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $sql = "SELECT 
                    cs.id,
                    u.full_name as user_name,
                    u.email as user_email,
                    cs.title,
                    cs.platform,
                    cs.video_url,
                    cs.category,
                    cs.status,
                    cs.created_at,
                    cs.approved_at
                FROM content_submissions cs
                JOIN users u ON cs.user_id = u.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY cs.created_at DESC";

        $headers = [
            'شناسه',
            'کاربر',
            'ایمیل',
            'عنوان',
            'پلتفرم',
            'URL',
            'دسته',
            'وضعیت',
            'تاریخ ثبت',
            'تاریخ تأیید'
        ];

        $result = $this->bulkService->exportToCSV(
            $sql,
            $params,
            $headers,
            'content_export'
        );

        if ($result['success']) {
            // دانلود فایل
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
            header('Cache-Control: no-cache');
            
            readfile($result['file_path']);
            exit;
        }

        return $this->response->json($result);
    }

    /**
     * لیست درآمدها
     */
    public function revenues()
    {
        $filters = [
            'status' => $_GET['status'] ?? null,
            'period' => $_GET['period'] ?? null,
        ];

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $revenues = $this->contentRevenueModel->getAll($filters, $perPage, $offset);
        $total = $this->contentRevenueModel->countAll($filters);
        $totalPages = (int)ceil($total / $perPage);

        return view('admin.content.revenues', [
            'user' => auth()->user(),
            'revenues' => $revenues,
            'total' => $total,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'filters' => $filters,
        ]);
    }

    /**
     * ایجاد درآمد جدید
     */
    public function createRevenue()
    {
        $id = (int)$this->request->param('id');
        $submission = $this->contentSubmissionModel->find($id);

        if (!$submission) {
            return view('errors.404');
        }

        return view('admin.content.revenue-create', [
            'user' => auth()->user(),
            'submission' => $submission,
        ]);
    }

    /**
     * ذخیره درآمد جدید
     */
    public function storeRevenue()
    {
        $input = $_POST;

        $validator = new Validator($input, [
            'submission_id' => 'required|integer',
            'period' => 'required',
            'total_revenue' => 'required|numeric|min:0',
            'user_share_percent' => 'required|numeric|min:0|max:100',
            'tax_percent' => 'required|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return $this->response->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->data();
        $result = $this->contentService->createRevenue((array)$data, user_id());

        if ($result['success']) {
            return redirect('/admin/content/revenues')
                ->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }
}
