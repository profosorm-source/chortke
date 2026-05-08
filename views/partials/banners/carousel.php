<?php if (empty($banners)) return; ?>
<div class="banner-carousel" data-placement="<?= e($placementSlug) ?>" data-rotation="<?= $placement->rotation_speed ?? 5000 ?>">
    <div class="carousel-container">
        <?php foreach ($banners as $index => $banner): ?>
            <div class="banner-slide <?= $index === 0 ? 'active' : '' ?>" data-banner-id="<?= $banner->id ?>">
                <?php if ($banner->image_path): ?>
                    <a href="<?= e($banner->link ?? '#') ?>" target="<?= e($banner->target ?? '_blank') ?>" onclick="registerBannerClick(<?= $banner->id ?>)">
                        <img src="<?= e($banner->image_path) ?>" alt="<?= e($banner->alt_text ?? $banner->title) ?>" loading="lazy">
                    </a>
                <?php elseif ($banner->custom_code): ?>
                    <div class="banner-custom"><?= $banner->custom_code ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (count($banners) > 1): ?>
        <div class="carousel-controls">
            <button class="carousel-prev" onclick="bannerCarouselPrev(this)">‹</button>
            <button class="carousel-next" onclick="bannerCarouselNext(this)">›</button>
        </div>
        <div class="carousel-indicators">
            <?php foreach ($banners as $index => $banner): ?>
                <span class="indicator <?= $index === 0 ? 'active' : '' ?>" onclick="bannerCarouselGoto(this, <?= $index ?>)"></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
.banner-carousel{position:relative;width:100%;overflow:hidden;border-radius:8px;margin:20px 0}
.carousel-container{position:relative;width:100%}
.banner-slide{display:none;width:100%}
.banner-slide.active{display:block}
.banner-slide img{width:100%;height:auto;display:block}
.carousel-controls{position:absolute;top:50%;width:100%;display:flex;justify-content:space-between;transform:translateY(-50%);padding:0 10px}
.carousel-prev,.carousel-next{background:rgba(0,0,0,0.5);color:#fff;border:none;width:40px;height:40px;border-radius:50%;cursor:pointer;font-size:24px;transition:background 0.3s}
.carousel-prev:hover,.carousel-next:hover{background:rgba(0,0,0,0.7)}
.carousel-indicators{text-align:center;padding:10px}
.carousel-indicators .indicator{display:inline-block;width:10px;height:10px;border-radius:50%;background:#ddd;margin:0 5px;cursor:pointer;transition:background 0.3s}
.carousel-indicators .indicator.active{background:#333}
</style>

<script>
function registerBannerClick(id){fetch('/api/banner-click',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({banner_id:id})})}
function bannerCarouselNext(btn){const c=btn.closest('.banner-carousel');const s=c.querySelectorAll('.banner-slide');const i=c.querySelectorAll('.indicator');let idx=Array.from(s).findIndex(x=>x.classList.contains('active'));s[idx].classList.remove('active');i[idx].classList.remove('active');idx=(idx+1)%s.length;s[idx].classList.add('active');i[idx].classList.add('active')}
function bannerCarouselPrev(btn){const c=btn.closest('.banner-carousel');const s=c.querySelectorAll('.banner-slide');const i=c.querySelectorAll('.indicator');let idx=Array.from(s).findIndex(x=>x.classList.contains('active'));s[idx].classList.remove('active');i[idx].classList.remove('active');idx=(idx-1+s.length)%s.length;s[idx].classList.add('active');i[idx].classList.add('active')}
function bannerCarouselGoto(ind,idx){const c=ind.closest('.banner-carousel');const s=c.querySelectorAll('.banner-slide');const i=c.querySelectorAll('.indicator');s.forEach(x=>x.classList.remove('active'));i.forEach(x=>x.classList.remove('active'));s[idx].classList.add('active');i[idx].classList.add('active')}
document.querySelectorAll('.banner-carousel').forEach(c=>{const r=parseInt(c.dataset.rotation)||5000;setInterval(()=>{const n=c.querySelector('.carousel-next');if(n)bannerCarouselNext(n)},r)});
</script>
