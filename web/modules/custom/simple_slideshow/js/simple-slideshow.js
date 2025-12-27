/**
 * @file
 * JavaScript for the Simple Slideshow.
 */
(function (Drupal) {
  'use strict';

  /**
   * Simple Slideshow behavior.
   */
  Drupal.behaviors.simpleSlideshow = {
    attach: function (context, settings) {
      // Find all slideshows on the page.
      const slideshows = context.querySelectorAll('.simple-slideshow-wrapper');

      slideshows.forEach(function (wrapper) {
        // Initialize each slideshow only once.
        if (wrapper.classList.contains('slideshow-processed')) {
          return;
        }
        wrapper.classList.add('slideshow-processed');

        const slideshow = wrapper.querySelector('.simple-slideshow');
        const slides = slideshow.querySelectorAll('.simple-slideshow-slide');
        const dots = wrapper.querySelectorAll('.simple-slideshow-dot');
        const prevButton = wrapper.querySelector('.simple-slideshow-prev');
        const nextButton = wrapper.querySelector('.simple-slideshow-next');

        // Get settings from data attributes.
        const autoplay = slideshow.dataset.autoplay === 'true';
        const autoplaySpeed = parseInt(slideshow.dataset.autoplaySpeed, 10);

        let currentSlide = 0;
        let autoplayInterval = null;

        // Function to show a specific slide.
        function showSlide(index) {
          // Handle index bounds.
          if (index < 0) {
            index = slides.length - 1;
          } else if (index >= slides.length) {
            index = 0;
          }

          // Hide all slides and show the current one.
          slides.forEach(function (slide, i) {
            slide.style.display = i === index ? 'flex' : 'none';
          });

          // Update active dot.
          dots.forEach(function (dot, i) {
            dot.classList.toggle('active', i === index);
          });

          currentSlide = index;
        }

        // Function to go to the next slide.
        function nextSlide() {
          showSlide(currentSlide + 1);
        }

        // Function to go to the previous slide.
        function prevSlide() {
          showSlide(currentSlide - 1);
        }

        // Initialize the slideshow.
        showSlide(0);

        // Set up autoplay if enabled.
        if (autoplay) {
          autoplayInterval = setInterval(nextSlide, autoplaySpeed);

          // Pause autoplay on hover.
          wrapper.addEventListener('mouseenter', function () {
            clearInterval(autoplayInterval);
          });

          wrapper.addEventListener('mouseleave', function () {
            autoplayInterval = setInterval(nextSlide, autoplaySpeed);
          });
        }

        // Set up navigation buttons.
        if (prevButton) {
          prevButton.addEventListener('click', function () {
            prevSlide();
            if (autoplay) {
              clearInterval(autoplayInterval);
              autoplayInterval = setInterval(nextSlide, autoplaySpeed);
            }
          });
        }

        if (nextButton) {
          nextButton.addEventListener('click', function () {
            nextSlide();
            if (autoplay) {
              clearInterval(autoplayInterval);
              autoplayInterval = setInterval(nextSlide, autoplaySpeed);
            }
          });
        }

        // Set up dot navigation.
        dots.forEach(function (dot) {
          dot.addEventListener('click', function () {
            const slideIndex = parseInt(dot.dataset.slide, 10);
            showSlide(slideIndex);
            if (autoplay) {
              clearInterval(autoplayInterval);
              autoplayInterval = setInterval(nextSlide, autoplaySpeed);
            }
          });
        });

        // Add swipe support for touch devices.
        let touchStartX = 0;
        let touchEndX = 0;

        slideshow.addEventListener('touchstart', function (e) {
          touchStartX = e.changedTouches[0].screenX;
        }, false);

        slideshow.addEventListener('touchend', function (e) {
          touchEndX = e.changedTouches[0].screenX;
          handleSwipe();
        }, false);

        function handleSwipe() {
          const swipeThreshold = 50; // Minimum distance for a swipe.
          if (touchEndX < touchStartX - swipeThreshold) {
            // Swipe left, go to next slide.
            nextSlide();
          } else if (touchEndX > touchStartX + swipeThreshold) {
            // Swipe right, go to previous slide.
            prevSlide();
          }

          if (autoplay) {
            clearInterval(autoplayInterval);
            autoplayInterval = setInterval(nextSlide, autoplaySpeed);
          }
        }
      });
    }
  };

})(Drupal);
