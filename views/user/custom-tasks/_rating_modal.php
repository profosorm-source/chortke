<?php
$title = $title ?? 'امتیازدهی';
$layout = 'user';
ob_start();
?>

<!-- Modal امتیازدهی -->
<div id="ratingModal" class="modal fade" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">امتیازدهی و نظر</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="ratingForm">
                    <input type="hidden" id="submission_id" name="submission_id">
                    
                    <!-- امتیاز ستاره‌ای -->
                    <div class="form-group">
                        <label>امتیاز شما:</label>
                        <div class="star-rating" dir="ltr">
                            <span class="star" data-rating="5">★</span>
                            <span class="star" data-rating="4">★</span>
                            <span class="star" data-rating="3">★</span>
                            <span class="star" data-rating="2">★</span>
                            <span class="star" data-rating="1">★</span>
                        </div>
                        <input type="hidden" id="rating" name="rating" required>
                        <small class="text-danger" id="rating-error"></small>
                    </div>

                    <!-- متن نظر -->
                    <div class="form-group">
                        <label>نظر شما (اختیاری):</label>
                        <textarea 
                            class="form-control" 
                            id="review_text" 
                            name="review_text" 
                            rows="4" 
                            placeholder="نظر خود را در مورد این تسک بنویسید..."
                            maxlength="1000"
                        ></textarea>
                        <small class="text-muted">حداقل 20 کاراکتر (در صورت تمایل)</small>
                        <small class="text-danger" id="review-error"></small>
                    </div>

                    <div class="alert alert-info">
                        <i class="fa fa-info-circle"></i>
                        نظر شما برای سایر کاربران قابل مشاهده خواهد بود و به بهبود کیفیت کمک می‌کند.
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">بستن</button>
                <button type="button" class="btn btn-primary" id="submitRating">ثبت امتیاز</button>
            </div>
        </div>
    </div>
</div>

<style>
.star-rating {
    font-size: 40px;
    direction: ltr;
    display: inline-block;
}

.star {
    cursor: pointer;
    color: #ddd;
    transition: color 0.2s;
    display: inline-block;
}

.star:hover,
.star.active {
    color: #ffc107;
}

.star:hover ~ .star {
    color: #ddd;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const stars = document.querySelectorAll('.star');
    const ratingInput = document.getElementById('rating');
    
    // انتخاب ستاره
    stars.forEach(star => {
        star.addEventListener('click', function() {
            const rating = this.dataset.rating;
            ratingInput.value = rating;
            
            // به‌روزرسانی نمایش
            stars.forEach(s => {
                if (s.dataset.rating <= rating) {
                    s.classList.add('active');
                } else {
                    s.classList.remove('active');
                }
            });
            
            document.getElementById('rating-error').textContent = '';
        });
        
        // Hover effect
        star.addEventListener('mouseenter', function() {
            const rating = this.dataset.rating;
            stars.forEach(s => {
                if (s.dataset.rating <= rating) {
                    s.style.color = '#ffc107';
                }
            });
        });
    });
    
    // Reset on mouse leave
    document.querySelector('.star-rating').addEventListener('mouseleave', function() {
        const selectedRating = ratingInput.value;
        stars.forEach(s => {
            if (selectedRating && s.dataset.rating <= selectedRating) {
                s.style.color = '#ffc107';
            } else {
                s.style.color = '#ddd';
            }
        });
    });
    
    // ثبت امتیاز
    document.getElementById('submitRating').addEventListener('click', function() {
        const submissionId = document.getElementById('submission_id').value;
        const rating = document.getElementById('rating').value;
        const reviewText = document.getElementById('review_text').value.trim();
        
        // Validation
        if (!rating) {
            document.getElementById('rating-error').textContent = 'لطفاً امتیاز خود را انتخاب کنید.';
            return;
        }
        
        // ارسال به سرور
        fetch('/user/custom-tasks/rate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({
                submission_id: submissionId,
                rating: parseInt(rating),
                review_text: reviewText
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                $('#ratingModal').modal('hide');
                showAlert('success', data.message);
                location.reload(); // Reload to show new rating
            } else {
                if (data.errors) {
                    if (data.errors.rating) {
                        document.getElementById('rating-error').textContent = data.errors.rating[0];
                    }
                    if (data.errors.review_text) {
                        document.getElementById('review-error').textContent = data.errors.review_text[0];
                    }
                } else {
                    showAlert('error', data.message);
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlert('error', 'خطا در ثبت امتیاز');
        });
    });
});

// تابع باز کردن Modal
function openRatingModal(submissionId) {
    document.getElementById('submission_id').value = submissionId;
    document.getElementById('rating').value = '';
    document.getElementById('review_text').value = '';
    document.getElementById('rating-error').textContent = '';
    document.getElementById('review-error').textContent = '';
    
    // Reset stars
    document.querySelectorAll('.star').forEach(s => {
        s.classList.remove('active');
        s.style.color = '#ddd';
    });
    
    $('#ratingModal').modal('show');
}

function showAlert(type, message) {
    // این تابع بسته به نوع Alert System شما متفاوت خواهد بود
    alert(message);
}
</script>
<?php $content = ob_get_clean(); include view_path('layouts.user');?>