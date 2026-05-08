<?php
$title = $title ?? 'ثبت اعتراض جدید';
$layout='user';
 ob_start();
?>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-4xl mx-auto">
        <!-- Header -->
        <div class="bg-white rounded-lg shadow-sm border p-6 mb-6">
            <div class="flex items-center">
                <a href="/appeals" class="text-gray-400 hover:text-gray-600 ml-4">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">ثبت اعتراض جدید</h1>
                    <p class="text-gray-600 mt-1">درخواست بررسی مجدد تصمیمات سیستم</p>
                </div>
            </div>
        </div>

        <!-- Form -->
        <div class="bg-white rounded-lg shadow-sm border">
            <form action="/appeals/store" method="POST" enctype="multipart/form-data" class="p-6">
                <?php echo csrf_field(); ?>

                <!-- Appeal Type -->
                <div class="mb-6">
                    <label for="appeal_type" class="block text-sm font-medium text-gray-700 mb-2">
                        نوع اعتراض <span class="text-red-500">*</span>
                    </label>
                    <select id="appeal_type" name="appeal_type" required
                            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">انتخاب کنید...</option>
                        <option value="fraud_suspension">تعلیق حساب به دلیل تقلب</option>
                        <option value="kyc_rejection">رد درخواست احراز هویت (KYC)</option>
                        <option value="order_dispute">اختلاف در سفارش</option>
                        <option value="verification_rejection">رد تأیید حساب اینفلوئنسر</option>
                        <option value="account_limitation">محدودیت حساب</option>
                        <option value="payment_dispute">اختلاف در پرداخت</option>
                        <option value="other">سایر</option>
                    </select>
                </div>

                <!-- Title -->
                <div class="mb-6">
                    <label for="title" class="block text-sm font-medium text-gray-700 mb-2">
                        عنوان اعتراض <span class="text-red-500">*</span>
                    </label>
                    <input type="text" id="title" name="title" required maxlength="255"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                           placeholder="عنوان مختصر اعتراض خود را وارد کنید">
                </div>

                <!-- Description -->
                <div class="mb-6">
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-2">
                        توضیحات <span class="text-red-500">*</span>
                    </label>
                    <textarea id="description" name="description" required rows="6"
                              class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                              placeholder="توضیحات کامل اعتراض خود را بنویسید. دلیل اعتراض، شواهد و مستندات مربوطه را ذکر کنید."></textarea>
                    <p class="mt-1 text-sm text-gray-500">حداکثر ۱۰۰۰ کاراکتر</p>
                </div>

                <!-- Reference ID (optional) -->
                <div class="mb-6">
                    <label for="reference_id" class="block text-sm font-medium text-gray-700 mb-2">
                        شماره مرجع (اختیاری)
                    </label>
                    <input type="text" id="reference_id" name="reference_id"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                           placeholder="شماره سفارش، تراکنش یا هر مرجع دیگری">
                    <p class="mt-1 text-sm text-gray-500">اگر اعتراض به یک سفارش یا تراکنش خاص مربوط است</p>
                </div>

                <!-- Attachments -->
                <div class="mb-6">
                    <label for="attachments" class="block text-sm font-medium text-gray-700 mb-2">
                        پیوست‌ها (اختیاری)
                    </label>
                    <div class="border-2 border-dashed border-gray-300 rounded-lg p-4">
                        <div class="text-center">
                            <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
                            </svg>
                            <div class="mt-4">
                                <label for="attachments" class="cursor-pointer">
                                    <span class="mt-2 block text-sm font-medium text-gray-900">فایل‌های خود را انتخاب کنید</span>
                                    <span class="mt-1 block text-sm text-gray-500">یا فایل‌ها را اینجا بکشید</span>
                                </label>
                                <input type="file" id="attachments" name="attachments[]" multiple
                                       class="hidden" accept="image/*,.pdf,.doc,.docx">
                            </div>
                        </div>
                    </div>
                    <p class="mt-2 text-sm text-gray-500">
                        حداکثر ۵ فایل، هر فایل حداکثر ۵ مگابایت (فرمت‌های مجاز: JPG, PNG, PDF, DOC, DOCX)
                    </p>
                    <div id="file-list" class="mt-2"></div>
                </div>

                <!-- Terms -->
                <div class="mb-6">
                    <div class="flex items-start">
                        <input type="checkbox" id="terms" name="terms" required
                               class="mt-1 h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                        <label for="terms" class="mr-3 text-sm text-gray-700">
                            با ارسال این اعتراض، تأیید می‌کنم که اطلاعات ارائه شده صحیح و کامل است و اعتراض من بر اساس واقعیات است.
                            همچنین می‌پذیرم که تصمیم نهایی توسط تیم بررسی خواهد شد.
                        </label>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="flex justify-end space-x-3 rtl:space-x-reverse">
                    <a href="/appeals" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-6 py-2 rounded-lg font-medium">
                        انصراف
                    </a>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                        ثبت اعتراض
                    </button>
                </div>
            </form>
        </div>

        <!-- Guidelines -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mt-6">
            <h3 class="text-lg font-medium text-blue-900 mb-4">راهنمای ثبت اعتراض</h3>
            <ul class="list-disc list-inside text-blue-800 space-y-2">
                <li>اعتراض باید بر اساس واقعیات و مستندات باشد</li>
                <li>از ارائه اطلاعات نادرست خودداری کنید</li>
                <li>پیوست‌های خود را به صورت واضح و خوانا آماده کنید</li>
                <li>زمان بررسی اعتراض معمولاً ۲۴-۴۸ ساعت است</li>
                <li>برای موارد اورژانسی، از طریق تیکت پشتیبانی تماس بگیرید</li>
            </ul>
        </div>
    </div>
</div>

<script>
// File upload preview
document.getElementById('attachments').addEventListener('change', function(e) {
    const files = e.target.files;
    const fileList = document.getElementById('file-list');

    fileList.innerHTML = '';

    for (let i = 0; i < files.length; i++) {
        const file = files[i];
        const fileItem = document.createElement('div');
        fileItem.className = 'flex items-center justify-between bg-gray-50 p-2 rounded mt-1';
        fileItem.innerHTML = `
            <span class="text-sm text-gray-700">${file.name}</span>
            <span class="text-sm text-gray-500">${(file.size / 1024 / 1024).toFixed(2)} MB</span>
        `;
        fileList.appendChild(fileItem);
    }
});
</script>
<?php $content = ob_get_clean(); include view_path('layouts.user');?>