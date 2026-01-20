document.addEventListener("DOMContentLoaded", function () {
  const lazyImages = document.querySelectorAll("img.lazyload");
  lazyImages.forEach((img) => {
    if (img.complete) {
      img.classList.add("loaded");
    } else {
      img.addEventListener("load", () => img.classList.add("loaded"));
    }
  });
});
