document.addEventListener('DOMContentLoaded', () => {
    console.log('JavaScript cargado correctamente.');

    const imagenes = document.querySelectorAll('.imagenes-interactivas img');
    const opciones = {
        root: null,
        rootMargin: '0px',
        threshold: 0.1
    };

    const callback = (entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                if (entry.target.classList.contains('izquierda')) {
                    entry.target.style.transform = 'translateX(0)';
                } else if (entry.target.classList.contains('derecha')) {
                    entry.target.style.transform = 'translateX(0)';
                }
                // Añadir rebote
                entry.target.classList.add('rebote');
                setTimeout(() => {
                    entry.target.classList.remove('rebote');
                }, 1000); // Duración del rebote en milisegundos
            }
        });
    };

    const observer = new IntersectionObserver(callback, opciones);
    imagenes.forEach(img => {
        observer.observe(img);
    });
});
