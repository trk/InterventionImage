document.addEventListener("DOMContentLoaded", function () {
  const markLoaded = (img) => {
    img.classList.add("loaded");
    if (img.style.backgroundImage) {
      img.style.backgroundImage = "";
      img.style.backgroundSize = "";
      img.style.backgroundPosition = "";
    }
  };
  const lazyImages = document.querySelectorAll("img.lazyload");
  lazyImages.forEach((img) => {
    if (img.complete) {
      markLoaded(img);
    } else {
      img.addEventListener("load", () => markLoaded(img), { once: true });
    }
  });
});
