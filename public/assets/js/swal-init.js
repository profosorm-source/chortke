(function () {
  if (typeof Swal === "undefined") return;

  // ✅ RTL واقعی: با setAttribute(dir=rtl)
  const didOpenRtl = (popup) => {
    try {
      popup.setAttribute('dir', 'rtl');
      popup.style.textAlign = 'right';
    } catch (e) {}
  };

  window.swalWithBootstrapButtons = Swal.mixin({
    customClass: {
      confirmButton: "btn btn-success ms-2",
      cancelButton: "btn btn-danger"
    },
    buttonsStyling: false,
    didOpen: didOpenRtl
  });

  window.swalConfirm = function (options) {
    const defaults = {
      title: "آیا مطمئن هستید؟",
      text: "این عملیات قابل بازگشت نیست.",
      icon: "warning",
      showCancelButton: true,
      confirmButtonText: "تأیید",
      cancelButtonText: "انصراف",
      reverseButtons: true
       
    };

    return window.swalWithBootstrapButtons.fire(Object.assign({}, defaults, options || {}));
  };

  window.swalSuccess = function (options) {
    const defaults = {
      title: "انجام شد",
      text: "عملیات با موفقیت انجام شد.",
      icon: "success",
      confirmButtonText: "باشه"
    };
    return window.swalWithBootstrapButtons.fire(Object.assign({}, defaults, options || {}));
  };

  window.swalError = function (options) {
    const defaults = {
      title: "خطا",
      text: "مشکلی رخ داد.",
      icon: "error",
      confirmButtonText: "باشه"
    };
    return window.swalWithBootstrapButtons.fire(Object.assign({}, defaults, options || {}));
  };
})();