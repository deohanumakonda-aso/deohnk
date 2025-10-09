document.addEventListener('DOMContentLoaded', function() {
    // 1. Sticky Navbar (already handled by CSS `position: sticky`)

    // 2. Live Clock for Right Sidebar
    const clockElement = document.getElementById('live-clock');
    const dateElement = document.getElementById('live-date');
    if (clockElement && dateElement) {
        function updateClock() {
            const now = new Date();
            const timeOptions = { 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit',
                hour12: true 
            };
            const dateOptions = { 
                weekday: 'long', 
                day: 'numeric', 
                month: 'long', 
                year: 'numeric' 
            };
            clockElement.textContent = now.toLocaleTimeString('en-US', timeOptions);
            dateElement.textContent = now.toLocaleDateString('en-US', dateOptions);
        }
        setInterval(updateClock, 1000);
        updateClock(); // Initial call
    }

    // 3. Image Carousel
    const carouselTrack = document.querySelector('.carousel-track');
    const slides = document.querySelectorAll('.carousel-slide');
    const prevBtn = document.querySelector('.prev-btn');
    const nextBtn = document.querySelector('.next-btn');
    
    console.log('Carousel elements found:', {
        track: !!carouselTrack,
        slides: slides.length,
        prevBtn: !!prevBtn,
        nextBtn: !!nextBtn
    });
    
    if (carouselTrack && slides.length > 0) {
        const slideWidth = 230 + 20; // slide width + gap
        let position = 0;
        
        // Clone all slides for continuous scrolling
        const originalSlides = Array.from(slides);
        originalSlides.forEach(slide => {
            const clone = slide.cloneNode(true);
            carouselTrack.appendChild(clone);
        });

        // Set all slides to active state
        document.querySelectorAll('.carousel-slide').forEach(slide => {
            slide.classList.add('active');
        });

        function moveSlides(direction) {
            const totalWidth = originalSlides.length * slideWidth;
            
            if (direction === 'next') {
                position -= slideWidth;
                carouselTrack.style.transition = 'transform 0.5s ease-in-out';
                carouselTrack.style.transform = `translateX(${position}px)`;

                // Reset position when reaching the cloned set
                if (Math.abs(position) >= totalWidth) {
                    setTimeout(() => {
                        carouselTrack.style.transition = 'none';
                        position = 0;
                        carouselTrack.style.transform = `translateX(${position}px)`;
                    }, 500);
                }
            } else {
                if (position === 0) {
                    position = -totalWidth;
                    carouselTrack.style.transition = 'none';
                    carouselTrack.style.transform = `translateX(${position}px)`;
                    setTimeout(() => {
                        position += slideWidth;
                        carouselTrack.style.transition = 'transform 0.5s ease-in-out';
                        carouselTrack.style.transform = `translateX(${position}px)`;
                    }, 20);
                } else {
                    position += slideWidth;
                    carouselTrack.style.transition = 'transform 0.5s ease-in-out';
                    carouselTrack.style.transform = `translateX(${position}px)`;
                }
            }
        }

        function nextSlide() {
            moveSlides('next');
        }

        function prevSlide() {
            moveSlides('prev');
        }
        
        // Event listeners
        if (nextBtn) nextBtn.addEventListener('click', nextSlide);
        if (prevBtn) prevBtn.addEventListener('click', prevSlide);
        
        // Auto-advance carousel every 3 seconds
        setInterval(nextSlide, 3000);
        
        // Initialize first slide
        console.log('Initializing carousel with', totalSlides, 'slides');
        console.log('Max slide index:', maxSlide);
        showSlide(0);
        
        // Test auto-advance after 5 seconds
        setTimeout(() => {
            console.log('Testing auto-advance...');
            nextSlide();
        }, 5000);
    } else {
        console.log('Carousel not initialized - missing elements');
    }
});