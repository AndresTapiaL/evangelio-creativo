document.addEventListener('DOMContentLoaded', () => {
    seleccionarOpcion('misionJoven');
});

const proyectos = {
    misionJoven: {
        titulo: 'Misión Joven/Embajadores',
        lideres: [
            { nombre: 'Milsa Morales', foto: 'images/lideres/path_to_foto_milsa_morales.jpg' },
            { nombre: 'Alejandro Muñoz', foto: 'images/lideres/path_to_foto_alejandro_munoz.jpg' }
        ],
        coordinadores: [
            { nombre: 'Yanina Castillo', foto: '' },
            { nombre: 'Katherine Cuevas', foto: '' },
            { nombre: 'Carolina Alvarengo', foto: '' }
        ]
    },
    alabanza: {
        titulo: 'Equipo de Alabanza',
        lideres: [
            { nombre: 'Enoc Contreras', foto: '' }
        ],
        coordinadores: [
            { nombre: 'Wladimir Castro', foto: '' }
        ]
    },
    kidsioneros: {
        titulo: 'Kidsioneros',
        lideres: [
            { nombre: 'Francisca Contreras', foto: '' }
        ],
        coordinadores: [
            { nombre: 'Rocío Román', foto: '' },
            { nombre: 'Débora Orellana', foto: '' },
            { nombre: 'Tracy Lira', foto: '' }
        ]
    },
    danzaTeatro: {
        titulo: 'Danza y Teatro',
        lideres: [
            { nombre: 'Patricio Tapia', foto: '' }
        ],
        coordinadores: [
            { nombre: 'Dafne Muñoz', foto: '' },
            { nombre: 'Ruth Sáez', foto: '' }
        ]
    },
    feEnMovimiento: {
        titulo: 'Fe en Movimiento',
        lideres: [
            { nombre: 'Felipe López', foto: '' }
        ],
        coordinadores: [
            { nombre: 'Isaac Valle', foto: '' }
        ]
    },
    comunicaciones: {
        titulo: 'Comunicaciones',
        lideres: [
            { nombre: 'Ignacia Madrid', foto: 'images/lideres/path_to_foto_ignacia_madrid.jpg' }
        ],
        coordinadores: [
            { nombre: 'Valentina Avello', foto: '' }
        ]
    },
    profesionales: {
        titulo: 'Profesionales al servicio de Evangelio Creativo',
        lideres: [
            { nombre: 'Carla Torres', foto: '' }
        ],
        coordinadores: []
    },
    difusion: {
        titulo: 'Difusión',
        lideres: [
            { nombre: 'Rebeca González', foto: 'images/lideres/path_to_foto_rebeca_gonzalez.jpg' }
        ],
        coordinadores: [
            { nombre: 'Jacy Urbina', foto: '' }
        ]
    },
    admision: {
        titulo: 'Admisión',
        lideres: [
            { nombre: 'Katherine Cuevas', foto: '' }
        ],
        coordinadores: [
            { nombre: 'Kelly Millapan', foto: '' }
        ]
    }
};

function seleccionarOpcion(proyecto) {
    const opciones = document.querySelectorAll('#proyectos-sidebar ul li a');
    opciones.forEach(opcion => {
        opcion.classList.remove('active');
    });

    const opcionSeleccionada = document.querySelector(`#proyectos-sidebar ul li a[data-proyecto="${proyecto}"]`);
    opcionSeleccionada.classList.add('active');
    mostrarProyecto(proyecto);
}

function mostrarProyecto(proyecto) {
    const proyectoInfo = document.getElementById('proyectoInfo');
    const data = proyectos[proyecto];

    let html = `<h3>${data.titulo}</h3>`;
    html += '<h4>Líderes:</h4><ul>';
    data.lideres.forEach(lider => {
        html += `<li><img src="${lider.foto}" alt="${lider.nombre}" class="foto"><span>${lider.nombre}</span></li>`;
    });
    html += '</ul><h4>Coordinadores:</h4><ul>';
    data.coordinadores.forEach(coordinador => {
        html += `<li><img src="${coordinador.foto}" alt="${coordinador.nombre}" class="foto"><span>${coordinador.nombre}</span></li>`;
    });
    html += '</ul>';
    proyectoInfo.innerHTML = html;

    // Añadir rebote a las nuevas imágenes
    const nuevasImagenes = document.querySelectorAll('#proyectoInfo .foto');
    nuevasImagenes.forEach(img => {
        img.classList.add('rebote');
        setTimeout(() => {
            img.classList.remove('rebote');
        }, 1000); // Duración del rebote en milisegundos
    });
}
