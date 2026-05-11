<?php
$title = 'داشبورد';
ob_start();

/* ── defaults ── */
$walletBalance      = $walletBalance      ?? 0;
$walletBalanceUsdt  = $walletBalanceUsdt  ?? 0;
$lockedBalance      = $lockedBalance      ?? 0;
$tasksCompleted     = $tasksCompleted     ?? 0;
$tasksPending       = $tasksPending       ?? 0;
$tasksRejected      = $tasksRejected      ?? 0;
$tasksTotal         = $tasksTotal         ?? ($tasksCompleted + $tasksPending + $tasksRejected);
$tasksEarned        = $tasksEarned        ?? 0;
$totalEarnings      = $totalEarnings      ?? 0;
$totalDeposits      = $totalDeposits      ?? 0;
$totalWithdraws     = $totalWithdraws     ?? 0;
$recentTransactions = $recentTransactions ?? [];
$activeCampaigns    = $activeCampaigns    ?? 0;
$recentAds          = $recentAds          ?? [];
$chartLabels        = $chartLabels        ?? [];
$chartData          = $chartData          ?? [];
$platformLabels     = $platformLabels     ?? [];
$platformData       = $platformData       ?? [];
$referralCount      = $referralCount      ?? 0;
$referralEarnings   = $referralEarnings   ?? 0;
$currentLevel       = $currentLevel       ?? 'SILVER';
$levelProgress      = $levelProgress      ?? 0;
$levelNext          = $levelNext          ?? null;
$activeInvestment   = $activeInvestment   ?? null;
$lotteryRound       = $lotteryRound       ?? null;
$socialPages        = $socialPages        ?? [];
$todayDailyNumber   = $todayDailyNumber   ?? null;
$todayVoteCounts    = $todayVoteCounts    ?? [];
$userTodayVote      = $userTodayVote      ?? null;
$totalDailyVotes    = $totalDailyVotes    ?? 0;
$pendingTxCount     = $pendingTxCount     ?? 0;
$recentTaskExecutions = $recentTaskExecutions ?? [];

$pIcons  = ['instagram'=>'camera_alt','youtube'=>'play_circle','telegram'=>'send','tiktok'=>'music_video','twitter'=>'flutter_dash','aparat'=>'ondemand_video'];
$pColors = ['instagram'=>'#E1306C','youtube'=>'#FF0000','telegram'=>'#0088cc','tiktok'=>'#333','twitter'=>'#1DA1F2','aparat'=>'#CC0000'];
$txMap   = [
    'deposit'           => ['واریز',           'add_circle',    'var(--success)', true ],
    'withdraw'          => ['برداشت',          'remove_circle', 'var(--danger)',  false],
    'task_reward'       => ['پاداش تسک',      'task_alt',      'var(--info)',    true ],
    'commission'        => ['کمیسیون',         'percent',       'var(--purple)',  true ],
    'refund'            => ['استرداد',         'replay',        'var(--warning)', true ],
    'content_revenue'   => ['درآمد محتوا',    'video_library', 'var(--info)',    true ],
    'investment_profit' => ['سود سرمایه‌گذاری','trending_up',  'var(--success)', true ],
    'lottery_prize'     => ['جایزه لاتاری',   'casino',        'var(--purple)',  true ],
];
$stMap = [
    'completed'  => ['موفق',      'var(--success)','var(--success-bg)','ok'],
    'pending'    => ['در انتظار', 'var(--warning)','var(--warning-bg)','wt'],
    'processing' => ['در پردازش', 'var(--warning)','var(--warning-bg)','wt'],
    'failed'     => ['ناموفق',    'var(--danger)', 'var(--danger-bg)', 'er'],
    'cancelled'  => ['لغو شده',   'var(--danger)', 'var(--danger-bg)', 'er'],
    'active'     => ['فعال',      'var(--success)','var(--success-bg)','ok'],
    'paused'     => ['متوقف',     'var(--info)',   'var(--info-bg)',   'wt'],
    'rejected'   => ['رد شده',    'var(--danger)', 'var(--danger-bg)', 'er'],
    'verified'   => ['تأیید شده', 'var(--success)','var(--success-bg)','ok'],
];

$sampleTx = [
    (object)['type'=>'deposit',    'id'=>'TXN-0001','amount'=>320000,'status'=>'completed'],
    (object)['type'=>'task_reward','id'=>'TXN-0002','amount'=>15000, 'status'=>'completed'],
    (object)['type'=>'withdraw',   'id'=>'TXN-0003','amount'=>200000,'status'=>'pending'],
    (object)['type'=>'commission', 'id'=>'TXN-0004','amount'=>8500,  'status'=>'completed'],
    (object)['type'=>'deposit',    'id'=>'TXN-0005','amount'=>500000,'status'=>'completed'],
];
$displayTx  = !empty($recentTransactions) ? $recentTransactions : $sampleTx;
$isSampleTx = empty($recentTransactions);

$sampleAds = [
    (object)['title'=>'کمپین اینستاگرام','platform'=>'instagram','task_type'=>'لایک و فالو','remaining_budget'=>54000,'status'=>'active'],
    (object)['title'=>'تبلیغ یوتیوب','platform'=>'youtube','task_type'=>'ویو و لایک','remaining_budget'=>120000,'status'=>'active'],
    (object)['title'=>'کانال تلگرام','platform'=>'telegram','task_type'=>'عضویت کانال','remaining_budget'=>12000,'status'=>'pending'],
    (object)['title'=>'کمپین توییتر','platform'=>'twitter','task_type'=>'ریتوییت','remaining_budget'=>32000,'status'=>'active'],
];
$displayAds  = !empty($recentAds) ? $recentAds : $sampleAds;
$isSampleAds = empty($recentAds);

$samplePages = [
    (object)['username'=>'my_instagram_page','platform'=>'instagram','followers_count'=>12400,'status'=>'verified'],
    (object)['username'=>'my_youtube_channel','platform'=>'youtube','followers_count'=>5800,'status'=>'pending'],
    (object)['username'=>'my_channel_tg','platform'=>'telegram','followers_count'=>3200,'status'=>'verified'],
];
$displayPages  = !empty($socialPages) ? $socialPages : $samplePages;
$isSamplePages = empty($socialPages);

$taskSuccess = $tasksTotal > 0 ? round($tasksCompleted / $tasksTotal * 100) : 0;
$levelEmoji  = ['BRONZE'=>'🥉','SILVER'=>'🥈','GOLD'=>'🥇','PLATINUM'=>'💎','DIAMOND'=>'💎'];
$lvEmoji     = $levelEmoji[$currentLevel] ?? '⭐';
?>

<?php if($pendingTxCount > 0): ?>
<div class="alert-bar" style="margin-bottom:14px">
  <span class="material-icons">schedule</span>
  <span><strong><?= e($pendingTxCount) ?> تراکنش</strong> در حال پردازش دارید.</span>
  <a href="<?= url('/wallet/history') ?>">مشاهده →</a>
</div>
<?php endif; ?>

<!-- ══ ROW 1: KPI Cards ══ -->
<div class="kpi-row">
  <!-- Wallet -->
  <div class="kc kc-w">
    <div class="kc-top"><div class="kc-ic"><span class="material-icons">account_balance_wallet</span></div><div class="kc-bdg bd-on"><span class="bd-dot"></span>فعال</div></div>
    <div class="kc-lbl">موجودی کیف پول</div>
    <div class="w-body">
      <div class="w-row">
        <div class="w-left"><div class="w-cic irt">﷼</div><div><div class="w-cn">تومان</div><div class="w-ca"><?= $walletBalance > 0 ? number_format($walletBalance) : '۰' ?></div></div></div>
        <a href="<?= url('/wallet/deposit') ?>" class="w-plus ip" title="واریز"><span class="material-icons">add</span></a>
      </div>
      <div class="w-row">
        <div class="w-left"><div class="w-cic usdt">$</div><div><div class="w-cn">تتر (USDT)</div><div class="w-ca usdt"><?= number_format($walletBalanceUsdt, 2) ?></div></div></div>
        <a href="<?= url('/wallet/deposit') ?>" class="w-plus up" title="واریز تتر"><span class="material-icons">add</span></a>
      </div>
    </div>
  </div>

  <!-- Tasks -->
  <div class="kc kc-t">
    <div class="kc-top"><div class="kc-ic"><span class="material-icons">task_alt</span></div><div class="kc-bdg bd-gn"><span class="bd-dot"></span><?= $tasksPending > 0 ? $tasksPending.' در انتظار' : 'به‌روز' ?></div></div>
    <div class="kc-lbl">تسک‌ها و درآمد</div>
    <div class="t-inc"><?= number_format($tasksEarned ?: 0) ?><span>تومان</span></div>
    <div class="t-stats">
      <div class="ts"><div class="ts-n al"><?= number_format($tasksTotal) ?></div><div class="ts-l">کل تسک</div></div>
      <div class="ts"><div class="ts-n dn"><?= number_format($tasksCompleted) ?></div><div class="ts-l">انجام‌شده</div></div>
      <div class="ts"><div class="ts-n pd"><?= number_format($tasksPending) ?></div><div class="ts-l">در انتظار</div></div>
    </div>
    <div class="t-bar">
      <div class="t-bar-bg"><div class="t-bar-fill" style="width:<?= e($taskSuccess) ?>%"></div></div>
      <div class="t-bar-lbl">نرخ موفقیت <strong><?= e($taskSuccess) ?>%</strong></div>
    </div>
  </div>

  <!-- Investment -->
  <div class="kc kc-i">
    <div class="kc-top"><div class="kc-ic"><span class="material-icons">trending_up</span></div>
      <?php
        $invStatus = $activeInvestment ? ($activeInvestment->status ?? 'active') : null;
        $invBadgeClass = 'bd-gn'; $invBadgeTxt = 'فعال';
        if(!$activeInvestment){ $invBadgeClass='bd-on'; $invBadgeTxt='بدون پلن'; }
        elseif($invStatus==='expired'||$invStatus==='closed'){ $invBadgeClass='bd-rd'; $invBadgeTxt='پایان‌یافته'; }
      ?>
      <div class="kc-bdg <?= $invBadgeClass ?>"><span class="bd-dot"></span><?= $invBadgeTxt ?></div>
    </div>
    <div class="kc-lbl">سرمایه‌گذاری</div>
    <?php if($activeInvestment): ?>
      <?php
        $invProfit = (float)($activeInvestment->profit_amount ?? $activeInvestment->expected_profit ?? 0);
        $invAmount = (float)($activeInvestment->amount ?? 0);
        $invProfitSign = $invProfit >= 0 ? '+' : '';
        $invProfitColor = $invProfit >= 0 ? '#4ade80' : '#f87171';
        $invEndDate = $activeInvestment->end_date ?? $activeInvestment->expire_date ?? null;
        $invPlanName = $activeInvestment->plan_name ?? $activeInvestment->plan_title ?? 'پلن سرمایه‌گذاری';
      ?>
      <div class="inv-kc-body">
        <div class="inv-kc-plan"><span class="material-icons">auto_graph</span><?= e($invPlanName) ?></div>
        <div class="inv-kc-row">
          <div class="inv-kc-item">
            <div class="inv-kc-lbl">مبلغ سرمایه</div>
            <div class="inv-kc-val"><?= number_format($invAmount) ?><span>تومان</span></div>
          </div>
          <div class="inv-kc-item">
            <div class="inv-kc-lbl">سود / زیان</div>
            <div class="inv-kc-val" style="color:<?= $invProfitColor ?>"><?= $invProfitSign ?><?= number_format($invProfit) ?><span>تومان</span></div>
          </div>
        </div>
        <?php if($invEndDate): ?>
        <div class="inv-kc-date"><span class="material-icons">event</span>پایان پلن: <?= e($invEndDate) ?></div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div style="padding:10px 12px">
        <div style="font-size:9.5px;color:rgba(255,255,255,.42);font-weight:600;margin-bottom:3px">پلن فعالی ندارید</div>
        <div style="font-size:17px;font-weight:900;color:rgba(255,255,255,.18);margin-bottom:8px">---</div>
        <a href="<?= url('/investment') ?>" style="display:inline-flex;align-items:center;gap:4px;padding:5px 10px;background:rgba(96,165,250,.15);border:1px solid rgba(96,165,250,.25);border-radius:6px;color:#60a5fa;font-size:10px;font-weight:800;text-decoration:none">
          <span class="material-icons" style="font-size:12px">add_circle</span>شروع سرمایه‌گذاری
        </a>
      </div>
    <?php endif; ?>
  </div>

  <!-- Lottery KPI -->
  <div class="kc kc-l">
    <div class="kc-top"><div class="kc-ic"><span class="material-icons">casino</span></div><div class="kc-bdg bd-gd">لاتاری</div></div>
    <div class="kc-lbl">قرعه‌کشی هفتگی</div>
    <?php if($lotteryRound): ?>
    <div class="lot-box">
      <div class="lot-hd"><span class="material-icons">timer</span>زمان باقی‌مانده</div>
      <div class="lot-cd">
        <div class="lot-u"><span class="lot-n" id="lotH">00</span><span class="lot-ul">ساعت</span></div>
        <span class="lot-sep">:</span>
        <div class="lot-u"><span class="lot-n" id="lotM">00</span><span class="lot-ul">دقیقه</span></div>
        <span class="lot-sep">:</span>
        <div class="lot-u"><span class="lot-n" id="lotS">00</span><span class="lot-ul">ثانیه</span></div>
      </div>
      <div class="lot-st lst-on" id="lotSt"><span class="lot-dot"></span>ثبت‌نام فعال است</div>
    </div>
    <?php else: ?>
    <div style="padding:10px 12px">
      <div style="font-size:10px;color:rgba(255,255,255,.4);font-weight:600;margin-bottom:8px">لاتاری فعالی وجود ندارد</div>
      <a href="<?= url('/lottery') ?>" style="display:inline-flex;align-items:center;gap:4px;padding:5px 10px;background:rgba(245,197,24,.12);border:1px solid rgba(245,197,24,.2);border-radius:6px;color:#F5C518;font-size:10px;font-weight:800;text-decoration:none">
        <span class="material-icons" style="font-size:12px">confirmation_number</span>مشاهده
      </a>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ══ ROW 2: Chart + Vote ══ -->
<div class="db-r2" style="margin-bottom:14px">
  <!-- نمودار -->
  <div class="wc">
    <div class="ctabs-w">
      <div class="ctabs">
        <button class="ctab on" onclick="stab(this,'inc')">درآمد</button>
        <button class="ctab"    onclick="stab(this,'tsk')">تسک‌ها</button>
        <button class="ctab"    onclick="stab(this,'plt')">پلتفرم</button>
        <button class="ctab"    onclick="stab(this,'dep')">مالی</button>
      </div>
      <a href="<?= url('/wallet/history') ?>" class="sml">بیشتر<span class="material-icons">arrow_back_ios</span></a>
    </div>
    <div style="width:100%;box-sizing:border-box;">
      <div id="ch-inc" style="position:relative;width:100%;height:320px;"><canvas id="cInc" style="display:block;"></canvas></div>
      <div id="ch-tsk" style="display:none;position:relative;width:100%;height:320px;"><canvas id="cTsk" style="display:block;"></canvas></div>
      <div id="ch-plt" style="display:none;position:relative;width:100%;height:320px;"><canvas id="cPlt" style="display:block;"></canvas></div>
      <div id="ch-dep" style="display:none;position:relative;width:100%;height:320px;"><canvas id="cDep" style="display:block;"></canvas></div>
    </div>
    <div class="chart-strip">
      <div class="cs-item"><div class="cs-v up"><?= number_format($totalEarnings ?: $tasksEarned) ?></div><div class="cs-l">کل درآمد</div></div>
      <div class="cs-item"><div class="cs-v"><?= number_format($tasksCompleted) ?></div><div class="cs-l">تسک انجام‌شده</div></div>
      <div class="cs-item"><div class="cs-v up"><?= number_format($walletBalanceUsdt,2) ?></div><div class="cs-l">موجودی USDT</div></div>
      <div class="cs-item"><div class="cs-v"><?= number_format($referralCount) ?></div><div class="cs-l">زیرمجموعه</div></div>
    </div>
  </div>

  <!-- ستون کنار: رأی‌گیری -->
  <div class="db-col" style="gap:0">
    <div class="wc" style="flex:1">
      <div class="wc-h"><h6><span class="material-icons">how_to_vote</span>رأی‌گیری روزانه</h6></div>
      <?php
        $voteIsSample = !$lotteryRound && !$todayDailyNumber;
        $sampleVoteCounts = ['0'=>12,'1'=>34,'2'=>8,'3'=>45,'4'=>21,'5'=>67,'6'=>15,'7'=>29,'8'=>53,'9'=>41];
        $sampleTotalVotes = array_sum($sampleVoteCounts);
        $displayVoteCounts = $voteIsSample ? $sampleVoteCounts : $todayVoteCounts;
        $displayTotalVotes = $voteIsSample ? $sampleTotalVotes : $totalDailyVotes;
      ?>
      <div class="vote-body" style="padding:10px 12px">
        <?php if($voteIsSample): ?>
          <div style="font-size:.73rem;color:var(--sub);font-weight:600;margin-bottom:7px">عدد خوش‌شانس امروز را انتخاب کنید</div>
          <div class="vote-nums" id="voteButtons" style="gap:5px">
            <?php for($n=0; $n<=9; $n++): ?>
            <button class="vn" data-num="<?= $n ?>" data-inactive="1"><?= $n ?></button>
            <?php endfor; ?>
          </div>
          <div style="font-size:.68rem;color:var(--muted);margin-top:6px;text-align:center">رأی‌گیری فعالی در جریان نیست</div>
        <?php elseif($userTodayVote): ?>
          <div class="vote-done-msg"><span class="material-icons">check_circle</span>رأی شما: <strong style="font-size:1rem;margin-right:4px"><?= e($userTodayVote->voted_number) ?></strong> ثبت شد!</div>
        <?php elseif($todayDailyNumber): ?>
          <div style="font-size:.73rem;color:var(--sub);font-weight:600;margin-bottom:7px">عدد خوش‌شانس امروز را انتخاب کنید</div>
          <div class="vote-nums" id="voteButtons" style="gap:5px">
            <?php for($n=0; $n<=9; $n++): ?>
            <button class="vn" data-num="<?= e($n) ?>"><?= e($n) ?></button>
            <?php endfor; ?>
          </div>
          <div id="voteMsg" style="display:none;font-size:.72rem;color:var(--muted);margin-top:4px"></div>
        <?php else: ?>
          <div style="font-size:.73rem;color:var(--sub);font-weight:600;margin-bottom:7px">عدد خوش‌شانس امروز را انتخاب کنید</div>
          <div class="vote-nums" id="voteButtons" style="gap:5px">
            <?php for($n=0; $n<=9; $n++): ?>
            <button class="vn" data-num="<?= $n ?>" data-inactive="2"><?= $n ?></button>
            <?php endfor; ?>
          </div>
        <?php endif; ?>
        <?php if($displayTotalVotes > 0): ?>
        <div class="vote-result" style="margin-top:8px">
          <div class="vote-result-title"><span class="material-icons">bar_chart</span>آمار امروز (<?= number_format($displayTotalVotes) ?> رأی)</div>
          <?php for($n=0; $n<=9; $n++): $cnt=$displayVoteCounts[(string)$n]??0; $pct=$displayTotalVotes>0?round($cnt/$displayTotalVotes*100):0; ?>
          <div class="vr-bar-row"><span class="vr-num"><?= $n ?></span><div class="vr-bar-bg"><div class="vr-bar-fill" style="width:<?= $pct ?>%"></div></div><span class="vr-cnt"><?= $cnt ?></span><span class="vr-pct"><?= $pct ?>%</span></div>
          <?php endfor; ?>
        </div>
        <?php endif; ?>
        <?php if($voteIsSample): ?><div class="s-note" style="margin-top:4px"><span class="material-icons">info</span>داده نمونه</div><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ══ لاتاری هیرو ══ -->
<?php
  $lhParticipants = 0; $lhUserTickets = 0; $lhPrize = 0; $lhPrizeTxt = '';
  $lhRoundNum = '—'; $lhHasData = false;
  if($lotteryRound){
    $lhHasData     = true;
    $lhParticipants= (int)($lotteryRound->participants_count ?? $lotteryRound->participant_count ?? 0);
    $lhUserTickets = (int)($lotteryRound->user_tickets ?? $lotteryRound->my_tickets ?? 0);
    $lhPrize       = (float)($lotteryRound->prize_pool ?? $lotteryRound->total_prize ?? $lotteryRound->prize_amount ?? 0);
    $lhPrizeTxt    = $lotteryRound->prize_description ?? $lotteryRound->prize_text ?? '';
    $lhRoundNum    = $lotteryRound->round_number ?? $lotteryRound->id ?? '—';
  }
  if(!$lhHasData){ $lhParticipants=248; $lhUserTickets=0; $lhPrize=5000000; $lhRoundNum='نمونه'; }
  $lhPrizeDisplay = $lhPrize >= 1000000 ? number_format($lhPrize/1000000,1).'M' : ($lhPrize > 0 ? number_format($lhPrize/1000).'K' : ($lhPrizeTxt ? mb_substr($lhPrizeTxt,0,8) : '🎁'));
?>
<div class="lhero" onclick="window.location='<?= url('/lottery') ?>'">
  <div class="lhero-label"><span class="material-icons">casino</span>قرعه‌کشی هفتگی<span class="lhero-round">رند #<?= e($lhRoundNum) ?></span></div>
  <div class="lhero-row">
    <div class="lhero-item">
      <span class="material-icons lhero-ic-purple">people</span>
      <div class="lhero-val"><?= number_format($lhParticipants) ?></div>
      <div class="lhero-lbl">شرکت‌کننده</div>
    </div>
    <div class="lhero-sep"></div>
    <div class="lhero-item">
      <span class="material-icons lhero-ic-blue">confirmation_number</span>
      <div class="lhero-val <?= $lhUserTickets > 0 ? 'lhero-green' : '' ?>"><?= $lhUserTickets > 0 ? $lhUserTickets : '—' ?></div>
      <div class="lhero-lbl">بلیط شما</div>
    </div>
    <div class="lhero-sep"></div>
    <div class="lhero-item">
      <span class="material-icons lhero-ic-gold">emoji_events</span>
      <div class="lhero-val lhero-gold"><?= e($lhPrizeDisplay) ?></div>
      <div class="lhero-lbl"><?= $lhPrize > 0 ? 'جایزه (تومان)' : 'جایزه' ?></div>
    </div>
    <div class="lhero-sep"></div>
    <a href="<?= url('/lottery') ?>" class="lhero-cta" onclick="event.stopPropagation()">
      <span><?= $lhUserTickets > 0 ? 'مشاهده' : 'شرکت کنید' ?></span>
      <span class="material-icons">arrow_back_ios</span>
    </a>
  </div>
</div>

<!-- ══ ROW 3: دوستونه - تسک‌های پیشنهادی + آخرین کمپین‌ها ══ -->
<div class="db-r4" style="margin-bottom:14px">
  <!-- تسک‌های پیشنهادی -->
  <div class="wc">
    <div class="wc-h"><h6><span class="material-icons">bolt</span>تسک‌های پیشنهادی</h6><a href="<?= url('/tasks') ?>" class="sml">همه</a></div>
    <div class="task-list">
      <?php
      $taskSamples=[
        ['فالو اینستاگرام @techpage','camera_alt','#E1306C',15200],
        ['ویو ویدیو یوتیوب','play_circle','#FF0000',20000],
        ['عضویت کانال تلگرام','send','#0088cc',11000],
        ['ریتوییت توییتر','flutter_dash','#1DA1F2',8500],
      ];
      foreach($taskSamples as $ts): ?>
      <div class="task-item">
        <div class="task-pl"><span class="material-icons" style="color:<?= $ts[2] ?>;font-size:17px"><?= $ts[1] ?></span></div>
        <div class="task-d"><div class="task-ttl"><?= $ts[0] ?></div><div class="task-rw">+<?= number_format($ts[3]) ?> تومان</div></div>
        <a href="<?= url('/tasks') ?>" class="task-btn">انجام</a>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="wc-f"><a href="<?= url('/tasks') ?>"><span class="material-icons">add_task</span>مشاهده همه تسک‌ها</a></div>
  </div>

  <!-- آخرین کمپین‌ها -->
  <div class="wc">
    <div class="wc-h"><h6><span class="material-icons">campaign</span>آخرین کمپین‌ها</h6><a href="<?= url('/advertiser') ?>" class="sml">همه</a></div>
    <div class="camp-list">
      <?php foreach(array_slice($displayAds,0,4) as $ad):
        $ad  = is_array($ad)?(object)$ad:$ad;
        $plt = $ad->platform ?? 'other';
        $pic = $pIcons[$plt] ?? 'campaign';
        $pcl = $pColors[$plt] ?? '#9A9AB0';
        $st  = $ad->status ?? 'active';
        [$sl,$sc,$sb,$sc2] = $stMap[$st] ?? ['فعال','var(--success)','transparent','ok'];
      ?>
      <div class="camp-item">
        <div class="camp-ic" style="background:linear-gradient(135deg,<?= e($pcl) ?>,<?= e($pcl) ?>bb)"><span class="material-icons"><?= e($pic) ?></span></div>
        <div style="flex:1">
          <div class="camp-name"><?= e($ad->title ?? 'کمپین') ?></div>
          <div class="camp-sub"><?= e($ad->task_type ?? 'تبلیغات') ?></div>
          <?php if((float)($ad->remaining_budget??0) > 0): ?>
          <div class="camp-amt"><?= number_format((float)$ad->remaining_budget) ?> تومان مانده</div>
          <?php else: ?><div class="camp-warn">بودجه تمام شده</div><?php endif; ?>
        </div>
        <span class="sdg <?= $sc2 ?>"><?= e($sl) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if($isSampleAds): ?><div class="s-note"><span class="material-icons">info</span>داده نمونه</div><?php endif; ?>
    <div class="wc-f"><a href="<?= url('/user/ad-tasks/create') ?>"><span class="material-icons">add_circle</span>کمپین جدید</a></div>
  </div>
</div>

<!-- ══ ROW 4: سه‌ستونه - پیج‌های ثبت‌شده + آخرین تراکنش‌ها + تاریخچه تسک‌ها ══ -->
<div class="db-r3" style="margin-bottom:14px">
  <!-- پیج‌های ثبت‌شده -->
  <div class="wc">
    <div class="wc-h"><h6><span class="material-icons">share</span>پیج‌های ثبت‌شده</h6><a href="<?= url('/social-accounts') ?>" class="sml"><span class="material-icons">add</span>افزودن</a></div>
    <div class="page-list">
      <?php foreach(array_slice($displayPages,0,3) as $pg):
        $pg=is_array($pg)?(object)$pg:$pg;
        $plt=$pg->platform??'instagram'; $pic=$pIcons[$plt]??'share'; $pcl=$pColors[$plt]??'#9A9AB0';
        $st=$pg->status??'pending'; [$sl,$sc,$sb,$sc2]=$stMap[$st]??['—','var(--muted)','transparent','wt'];
      ?>
      <div class="page-item">
        <div class="page-ic" style="background:linear-gradient(135deg,<?= e($pcl) ?>,<?= e($pcl) ?>99)"><span class="material-icons"><?= e($pic) ?></span></div>
        <div><div class="page-name">@<?= e($pg->username??'نام کاربری') ?></div><div class="page-sub"><?= number_format($pg->followers_count??0) ?> دنبال‌کننده</div><span class="page-st <?= $sc2 ?>"><span class="material-icons"><?= $sc2==='ok'?'verified':'schedule' ?></span><?= e($sl) ?></span></div>
      </div>
      <?php endforeach; ?>
      <div class="page-item" style="border-style:dashed;justify-content:center;flex-direction:column;align-items:center;gap:4px;cursor:pointer;min-height:56px;opacity:.6" onclick="window.location='<?= url('/social-accounts') ?>'">
        <span class="material-icons" style="font-size:20px;color:var(--muted)">add_circle</span>
        <span style="font-size:10px;color:var(--muted);font-weight:600">افزودن پیج جدید</span>
      </div>
    </div>
    <?php if($isSamplePages): ?><div class="s-note"><span class="material-icons">info</span>داده نمونه</div><?php endif; ?>
    <div class="wc-f"><a href="<?= url('/social-accounts') ?>"><span class="material-icons">manage_accounts</span>مدیریت پیج‌ها</a></div>
  </div>

  <!-- آخرین تراکنش‌ها -->
  <div class="wc">
    <div class="wc-h"><h6><span class="material-icons">receipt_long</span>آخرین تراکنش‌ها</h6><a href="<?= url('/wallet/history') ?>" class="sml"><span class="material-icons">arrow_back_ios</span>همه</a></div>
    <table class="xtbl">
      <thead><tr><th>نوع</th><th>شناسه</th><th>مبلغ (تومان)</th><th>وضعیت</th></tr></thead>
      <tbody>
      <?php foreach(array_slice($displayTx,0,5) as $tx):
        $ti  = $txMap[$tx->type??''] ?? ['تراکنش','payments','var(--muted)',true];
        $st  = $tx->status ?? 'pending';
        [$sl,$sc,$sb,$sc2] = $stMap[$st] ?? ['—','var(--muted)','transparent','wt'];
        $inc = $ti[3];
      ?>
      <tr>
        <td><div style="display:flex;align-items:center;gap:5px"><span class="material-icons" style="font-size:13px;color:<?= e($ti[2]) ?>"><?= e($ti[1]) ?></span><span><?= e($ti[0]) ?></span></div></td>
        <td style="font-size:.67rem;color:var(--muted);direction:ltr"><?= e(substr($tx->id??'—',0,8)) ?></td>
        <td class="<?= $inc?'ap':'am' ?>"><?= $inc?'+':'-' ?><?= number_format((float)($tx->amount??0)) ?></td>
        <td><span class="sdg <?= $sc2 ?>"><?= e($sl) ?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if($isSampleTx): ?><div class="s-note"><span class="material-icons">info</span>داده نمونه</div><?php endif; ?>
    <div class="wc-f"><a href="<?= url('/wallet/history') ?>"><span class="material-icons">visibility</span>مشاهده همه تراکنش‌ها</a></div>
  </div>

  <!-- تاریخچه تسک‌ها -->
  <div class="wc">
    <div class="wc-h"><h6><span class="material-icons">history</span>تاریخچه تسک‌ها</h6><a href="<?= url('/tasks/history') ?>" class="sml">همه</a></div>
    <?php
    $pHistColors=['instagram'=>'#E1306C','youtube'=>'#FF0000','telegram'=>'#0088cc','twitter'=>'#1DA1F2','tiktok'=>'#333','other'=>'#6B7280'];
    $pHistIcons=['instagram'=>'camera_alt','youtube'=>'play_circle','telegram'=>'send','twitter'=>'flutter_dash','tiktok'=>'music_video','other'=>'task_alt'];
    $taskHistSamples=[
      ['فالو اینستاگرام','instagram','لایک و فالو',15200,'completed'],
      ['ویو یوتیوب','youtube','ویو ویدیو',20000,'completed'],
      ['عضویت کانال','telegram','عضویت',11000,'pending'],
      ['ریتوییت توییتر','twitter','ریتوییت',8500,'completed'],
    ];
    $isSampleTasks = empty($recentTaskExecutions);
    ?>
    <div class="th-rows">
      <?php if($isSampleTasks): foreach($taskHistSamples as $r):
        [$sl,$sc,$sb,$sc2]=$stMap[$r[4]]??['—','var(--muted)','transparent','wt'];
        $plt=$r[1]; $pic=$pHistIcons[$plt]??'task_alt'; $pcl=$pHistColors[$plt]??'#6B7280';
      ?>
      <div class="th-row">
        <div class="th-pl" style="background:linear-gradient(135deg,<?= $pcl ?>,<?= $pcl ?>99)"><span class="material-icons"><?= $pic ?></span></div>
        <div style="flex:1"><div class="th-name"><?= e($r[0]) ?></div><div class="th-type"><?= e($r[2]) ?></div></div>
        <div style="text-align:left"><div class="th-amt">+<?= number_format($r[3]) ?></div><span class="sdg <?= $sc2 ?>" style="font-size:9px"><?= e($sl) ?></span></div>
      </div>
      <?php endforeach; else: foreach(array_slice($recentTaskExecutions,0,4) as $te):
        $te=is_array($te)?(object)$te:$te;
        $st=$te->status??'pending';
        [$sl,$sc,$sb,$sc2]=$stMap[$st]??['—','var(--muted)','transparent','wt'];
        $plt=$te->platform??'other'; $pic=$pHistIcons[$plt]??'task_alt'; $pcl=$pHistColors[$plt]??'#6B7280';
      ?>
      <div class="th-row">
        <div class="th-pl" style="background:linear-gradient(135deg,<?= e($pcl) ?>,<?= e($pcl) ?>99)"><span class="material-icons"><?= e($pic) ?></span></div>
        <div style="flex:1"><div class="th-name"><?= e($te->ad_title??$te->task_title??'—') ?></div><div class="th-type"><?= e($te->task_type??'—') ?></div></div>
        <div style="text-align:left"><div class="th-amt">+<?= number_format((float)($te->reward_amount??0)) ?></div><span class="sdg <?= $sc2 ?>" style="font-size:9px"><?= e($sl) ?></span></div>
      </div>
      <?php endforeach; endif; ?>
    </div>
    <?php if($isSampleTasks): ?><div class="s-note"><span class="material-icons">info</span>داده نمونه</div><?php endif; ?>
    <div class="wc-f"><a href="<?= url('/tasks') ?>"><span class="material-icons">add_task</span>انجام تسک جدید</a></div>
  </div>
</div>

<!-- Charts + JS -->
<script src="<?= asset('assets/vendor/chartjs/chart.umd.min.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  function isDark(){ return document.documentElement.getAttribute('data-theme') === 'dark'; }
  function gc(){ return isDark()?'rgba(255,255,255,.06)':'rgba(0,0,0,.05)'; }
  function tc(){ return isDark()?'#848E9C':'#9A9AB0'; }
  Chart.defaults.font.family = "'Vazirmatn',Tahoma,sans-serif";
  function grad(ctx,a,b){ var g=ctx.createLinearGradient(0,0,0,250); g.addColorStop(0,a); g.addColorStop(1,b); return g; }
  var el, cx;

  el=document.getElementById('cInc'); if(el){ cx=el.getContext('2d');
    new Chart(el,{type:'line',data:{
      labels:<?= json_encode($chartLabels ?: array_map(fn($i)=>"روز $i",range(1,30))) ?>,
      datasets:[{label:'درآمد',data:<?= json_encode($chartData ?: array_fill(0,30,0)) ?>,
        borderColor:'#C99A00',backgroundColor:grad(cx,'rgba(201,154,0,.35)','rgba(201,154,0,.01)'),
        borderWidth:2,tension:0.42,fill:true,pointRadius:0,pointHoverRadius:5,pointBackgroundColor:'#C99A00',pointBorderWidth:2}]
    },options:{responsive:true,maintainAspectRatio:false,layout:{padding:{top:10,right:6,bottom:6,left:4}},interaction:{mode:'index',intersect:false},
      plugins:{legend:{display:false},tooltip:{rtl:true,backgroundColor:isDark()?'#2B3139':'#1A1A2E',titleColor:'#C99A00',bodyColor:'#fff',padding:8,cornerRadius:8,callbacks:{label:c=>' '+c.parsed.y.toLocaleString()+' تومان'}}},
      scales:{x:{grid:{color:gc()},ticks:{font:{size:9},color:tc()}},y:{beginAtZero:true,grid:{color:gc()},ticks:{callback:v=>v>=1000?(v/1000)+'K':v,font:{size:9},color:tc()}}}}}); }

  el=document.getElementById('cTsk'); if(el){ new Chart(el,{type:'bar',data:{
    labels:['انجام‌شده','در انتظار','رد شده'],datasets:[{data:[<?= (int)$tasksCompleted ?>,<?= (int)$tasksPending ?>,<?= (int)$tasksRejected ?>],backgroundColor:['#18B95A','#C99A00','#E53E3E'],borderRadius:6,borderSkipped:false}]
  },options:{responsive:true,maintainAspectRatio:false,layout:{padding:{top:10,right:6,bottom:2,left:4}},plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,grid:{color:gc()},ticks:{color:tc()}},x:{grid:{display:false},ticks:{color:tc()}}}}}); }

  el=document.getElementById('cPlt'); if(el){ new Chart(el,{type:'doughnut',data:{
    labels:<?= json_encode($platformLabels ?: ['اینستاگرام','یوتیوب','تلگرام']) ?>,datasets:[{data:<?= json_encode($platformData ?: [1,1,1]) ?>,backgroundColor:['#E1306C','#FF0000','#0088cc','#CC0000','#1DA1F2','#C99A00'],borderWidth:0,hoverOffset:4}]
  },options:{responsive:true,maintainAspectRatio:false,layout:{padding:8},cutout:'62%',plugins:{legend:{position:'bottom',labels:{font:{size:9},padding:7,boxWidth:7,color:tc()}}}}}); }

  el=document.getElementById('cDep'); if(el){ var net=<?= (int)($totalDeposits-$totalWithdraws) ?>;
    new Chart(el,{type:'bar',data:{labels:['واریز','برداشت','موجودی خالص'],datasets:[{data:[<?= (int)$totalDeposits ?>,<?= (int)$totalWithdraws ?>,net],backgroundColor:['#18B95A','#E53E3E',net>=0?'#3B82F6':'#E53E3E'],borderRadius:6,borderSkipped:false}]},
    options:{responsive:true,maintainAspectRatio:false,layout:{padding:{top:10,right:6,bottom:2,left:4}},plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,grid:{color:gc()},ticks:{color:tc()}},x:{grid:{display:false},ticks:{color:tc()}}}}}); }
});

function stab(btn,tab){
  document.querySelectorAll('.ctab').forEach(b=>b.classList.remove('on')); btn.classList.add('on');
  ['inc','tsk','plt','dep'].forEach(t=>{ var e=document.getElementById('ch-'+t); if(e) e.style.display=t===tab?'block':'none'; });
}

/* ── سیستم رأی‌گیری یکپارچه ── */
(function(){

  /* ─ toast از پایین ─ */
  function showToast(msg, type){
    var t = document.createElement('div');
    t.className = 'vote-toast vote-toast-' + type;
    t.innerHTML = '<span class="material-icons">' + (type==='success' ? 'check_circle' : type==='warn' ? 'info' : 'error') + '</span><span>' + msg + '</span>';
    document.body.appendChild(t);
    /* force reflow then animate in */
    t.getBoundingClientRect();
    t.classList.add('vt-show');
    setTimeout(function(){
      t.classList.remove('vt-show');
      setTimeout(function(){ if(t.parentNode) t.parentNode.removeChild(t); }, 400);
    }, 3500);
  }

  /* ─ modal تأییدیه ─ */
  var modal = document.createElement('div');
  modal.id = 'vcm';
  modal.innerHTML =
    '<div class="vcm-backdrop"></div>' +
    '<div class="vcm-box">' +
      '<div class="vcm-icon"><span class="material-icons">how_to_vote</span></div>' +
      '<div class="vcm-title">تأیید رأی</div>' +
      '<div class="vcm-body">آیا مطمئنید می‌خواهید به عدد <strong class="vcm-num" id="vcmNum"></strong> رأی بدهید؟</div>' +
      '<div class="vcm-note">بعد از ثبت، رأی قابل تغییر نیست.</div>' +
      '<div class="vcm-btns">' +
        '<button class="vcm-btn-cancel" id="vcmCancel">انصراف</button>' +
        '<button class="vcm-btn-confirm" id="vcmConfirm"><span class="material-icons">check_circle</span>بله، ثبت شود</button>' +
      '</div>' +
    '</div>';
  document.body.appendChild(modal);

  var _pendingNum = null;

  function openModal(num){
    _pendingNum = num;
    document.getElementById('vcmNum').textContent = num;
    modal.classList.add('vcm-open');
    var box = modal.querySelector('.vcm-box');
    box.style.cssText = 'transform:scale(.82);opacity:0;transition:none';
    box.getBoundingClientRect();
    box.style.cssText = 'transform:scale(1);opacity:1;transition:transform .24s cubic-bezier(.34,1.56,.64,1),opacity .18s';
  }
  function closeModal(){
    var box = modal.querySelector('.vcm-box');
    box.style.cssText = 'transform:scale(.82);opacity:0;transition:transform .18s,opacity .15s';
    setTimeout(function(){ modal.classList.remove('vcm-open'); }, 180);
    document.querySelectorAll('.vn').forEach(function(b){ b.classList.remove('sel'); });
    _pendingNum = null;
  }

  modal.querySelector('.vcm-backdrop').addEventListener('click', closeModal);
  document.getElementById('vcmCancel').addEventListener('click', closeModal);
  document.getElementById('vcmConfirm').addEventListener('click', function(){
    closeModal();
    if(_pendingNum !== null) castVote(_pendingNum);
  });

  /* ─ بایند دکمه‌ها ─ */
  document.querySelectorAll('.vn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var inactive = this.getAttribute('data-inactive');
      var num = parseInt(this.getAttribute('data-num'));
      if(inactive === '1'){
        showToast('رأی‌گیری فعالی در جریان نیست', 'warn');
        return;
      }
      if(inactive === '2'){
        showToast('عدد امروز هنوز تعیین نشده است', 'warn');
        return;
      }
      document.querySelectorAll('.vn').forEach(function(b){ b.classList.remove('sel'); });
      this.classList.add('sel');
      openModal(num);
    });
  });

  /* ─ ارسال رأی ─ */
  <?php if($lotteryRound && $todayDailyNumber && !$userTodayVote): ?>
  function castVote(num){
    document.querySelectorAll('.vn').forEach(function(b){ b.disabled = true; });
    var msg = document.getElementById('voteMsg');
    if(msg){ msg.style.display='block'; msg.textContent='در حال ثبت رأی...'; }
    fetch('<?= url('/user/lottery/vote') ?>', {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-Token': window.csrfToken || (document.querySelector('meta[name="csrf-token"]')||{}).content || ''},
      body: JSON.stringify({round_id:<?= (int)($lotteryRound->id??0) ?>, voted_number:num, daily_number_id:<?= (int)($todayDailyNumber->id??0) ?>})
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
      if(data.success){
        var wrap = document.getElementById('voteButtons');
        if(wrap) wrap.innerHTML = '<div class="vote-done-msg"><span class="material-icons">check_circle</span>رأی شما (<strong style="font-size:1.1rem;margin-right:4px">' + num + '</strong>) ثبت شد!</div>';
        if(msg) msg.style.display='none';
        showToast('رأی شما به عدد ' + num + ' با موفقیت ثبت شد 🎯', 'success');
      } else {
        if(msg) msg.textContent = data.message || 'خطا در ثبت رأی';
        document.querySelectorAll('.vn').forEach(function(b){ b.disabled = false; });
        showToast(data.message || 'خطا در ثبت رأی', 'error');
      }
    })
    .catch(function(){
      if(msg) msg.textContent = 'خطا در اتصال';
      document.querySelectorAll('.vn').forEach(function(b){ b.disabled = false; });
      showToast('خطا در اتصال به سرور', 'error');
    });
  }
  <?php else: ?>
  function castVote(num){ showToast('رأی‌گیری در این لحظه فعال نیست', 'warn'); }
  <?php endif; ?>

})();

<?php if($lotteryRound && !empty($lotteryRound->end_date)): ?>
(function(){
  var end=<?= e(strtotime($lotteryRound->end_date)) ?>;
  var H=document.getElementById('lotH'),M=document.getElementById('lotM'),S=document.getElementById('lotS'),st=document.getElementById('lotSt');
  if(!H) return;
  function p(n){return String(n).padStart(2,'0');}
  function tick(){
    var s=end-Math.floor(Date.now()/1000);
    if(s<=0){H.textContent=M.textContent=S.textContent='00';if(st){st.className='lot-st lst-off';st.innerHTML='<span class="lot-dot"></span>زمان پایان یافت';}return;}
    H.textContent=p(Math.floor(s%86400/3600)); M.textContent=p(Math.floor(s%3600/60)); S.textContent=p(s%60);
  }
  tick(); setInterval(tick,1000);
})();
<?php endif; ?>
</script>

<?php
$content = ob_get_clean();
include view_path('layouts.user');
?>