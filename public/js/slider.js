/**
 * Sliders placed with [slider:slug]: previous and next buttons, dots, and an
 * optional timer (data-seconds) that pauses while the pointer or keyboard focus
 * is on the slider. Without this script, the first slide is shown.
 */
(function () {
    'use strict';

    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function setUp(slider) {
        if (slider.dataset.ready) {
            return;
        }

        slider.dataset.ready = 'true';

        var track = slider.querySelector('.slider-track');
        var slides = Array.prototype.slice.call(track.children);
        var dots = slider.querySelectorAll('.slider-dots button');
        var count = slides.length;
        var isSliding = slider.classList.contains('animation-slide');
        var current = 0;
        var isMoving = false;
        var timer = null;

        if (count < 2) {
            return;
        }

        // Read before the track's own transition is switched off below.
        var changeSeconds = parseFloat(getComputedStyle(isSliding ? track : slides[0]).transitionDuration) || 0;
        var secondsPerSlide = parseFloat(slider.dataset.seconds);

        // Each slide, with its copy if it has one, so both zoom in step.
        var copiesOf = slides.map(function (slide) {
            return [slide];
        });
        var leavingTimers = [];

        // A slow zoom lasts as long as the slide shows and through the change to the next.
        if (secondsPerSlide > 0) {
            slider.style.setProperty('--slide-zoom-duration', (secondsPerSlide + changeSeconds) + 's');
        }

        // In the slide animation, a copy of the last slide sits before the first and a
        // copy of the first after the last, so the slider can carry on past either end
        // and then jump, unseen, back to the real slide.
        if (isSliding) {
            var firstCopy = slides[0].cloneNode(true);
            var lastCopy = slides[count - 1].cloneNode(true);

            [firstCopy, lastCopy].forEach(function (copy) {
                copy.classList.remove('is-active');
                copy.setAttribute('aria-hidden', 'true');
            });

            track.appendChild(firstCopy);
            track.insertBefore(lastCopy, slides[0]);
            copiesOf[0].push(firstCopy);
            copiesOf[count - 1].push(lastCopy);
            moveTrack(1, false);

            track.addEventListener('transitionend', function (event) {
                if (event.target === track) {
                    settle();
                }
            });
        }

        slider.classList.add('is-ready');

        function moveTrack(position, animate) {
            track.style.transition = animate && ! reduceMotion ? '' : 'none';
            track.style.transform = 'translateX(' + (-position * 100) + '%)';
        }

        function settle() {
            isMoving = false;
            moveTrack(current + 1, false);
        }

        // The slide being left stays marked until it's out of sight, so its zoom carries on.
        function markLeaving(index) {
            copiesOf[index].forEach(function (slide) {
                slide.classList.remove('is-active');
                slide.classList.add('is-leaving');
            });

            window.clearTimeout(leavingTimers[index]);
            leavingTimers[index] = window.setTimeout(function () {
                copiesOf[index].forEach(function (slide) {
                    slide.classList.remove('is-leaving');
                });
            }, changeSeconds * 1000 + 100);
        }

        function markActive(index) {
            window.clearTimeout(leavingTimers[index]);

            copiesOf[index].forEach(function (slide) {
                slide.classList.remove('is-leaving');
                slide.classList.add('is-active');
            });
        }

        function show(index) {
            if (isMoving) {
                return;
            }

            var next = (index + count) % count;

            markLeaving(current);
            markActive(next);
            dots[current].removeAttribute('aria-current');
            dots[next].setAttribute('aria-current', 'true');

            if (isSliding) {
                isMoving = ! reduceMotion;
                moveTrack(index + 1, true);
            }

            current = next;

            if (isSliding && reduceMotion) {
                settle();
            }
        }

        function startTimer() {
            var seconds = parseFloat(slider.dataset.seconds);

            stopTimer();

            if (seconds > 0 && ! reduceMotion) {
                timer = window.setInterval(function () {
                    show(current + 1);
                }, seconds * 1000);
            }
        }

        function stopTimer() {
            window.clearInterval(timer);
        }

        slider.querySelector('.slider-prev').addEventListener('click', function () {
            show(current - 1);
        });

        slider.querySelector('.slider-next').addEventListener('click', function () {
            show(current + 1);
        });

        dots.forEach(function (dot, index) {
            dot.addEventListener('click', function () {
                show(index);
            });
        });

        slider.addEventListener('mouseenter', stopTimer);
        slider.addEventListener('mouseleave', startTimer);
        slider.addEventListener('focusin', stopTimer);
        slider.addEventListener('focusout', startTimer);

        startTimer();
    }

    document.querySelectorAll('.slider').forEach(setUp);
})();
