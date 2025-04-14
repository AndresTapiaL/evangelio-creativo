document.addEventListener('DOMContentLoaded', () => {
    mostrarDirectorio();
});

const equipos = {
    coquimbo: {
        lideres: [
            { nombre: 'Aron', apellido: 'Vicuña', foto: 'images/lideres/path_to_foto_aron_vicuna.jpg' },
            { nombre: 'Raquel', apellido: 'Marambio', foto: 'images/lideres/path_to_foto_raquel_marambio.jpg' }
        ],
        coordinadores: [
            { nombre: 'Jesús', apellido: 'Leal', foto: 'images/lideres/path_to_foto_jesus_leal.jpg' },
            { nombre: 'Nallely', apellido: 'Paredes', foto: 'images/lideres/path_to_foto_nallely_paredes.jpg' }
        ],
        imagen: 'images/lideres/path_to_imagen_coquimbo.jpg'
    },
    valparaiso: {
        lideres: [
            { nombre: 'Diego', apellido: 'Ramos', foto: 'images/lideres/path_to_foto_diego_ramos.jpg' }
        ],
        coordinadores: [
            { nombre: 'Kathalyna', apellido: 'Álvarez', foto: 'images/lideres/path_to_foto_kathalyna_alvarez.jpg' },
            { nombre: 'Nicolás', apellido: 'Arancibia', foto: 'images/lideres/path_to_foto_nicolas_arancibia.jpg' },
            { nombre: 'Rachel', apellido: 'Orozco', foto: 'images/lideres/path_to_foto_rachel_orozco.jpg' }
        ],
        imagen: 'images/lideres/path_to_imagen_valparaiso.jpg'
    },
    metropolitana: {
        lideres: [
            { nombre: 'Felipe', apellido: 'Saldías', foto: 'images/lideres/path_to_foto_felipe_saldias.jpg' }
        ],
        coordinadores: [
            { nombre: 'Débora', apellido: 'Orellana', foto: 'images/lideres/path_to_foto_debora_orellana.jpg' },
            { nombre: 'Abías', apellido: 'Almonacid', foto: 'images/lideres/path_to_foto_abias_almonacid.jpg' },
            { nombre: 'Yenifer', apellido: 'Sáez', foto: 'images/lideres/path_to_foto_yenifer_saez.jpg' },
            { nombre: 'Byron', apellido: 'Faúndez', foto: 'images/lideres/path_to_foto_byron_faundez.jpg' }
        ],
        imagen: 'images/lideres/path_to_imagen_metropolitana.jpg'
    },
    ohiggins: {
        lideres: [
            { nombre: 'Elías', apellido: 'Núñez', foto: 'images/lideres/path_to_foto_elias_nunez.jpg' }
        ],
        coordinadores: [
            { nombre: 'Matías', apellido: 'Campos', foto: 'images/lideres/path_to_foto_matias_campos.jpg' },
            { nombre: 'Carla', apellido: 'Díaz', foto: 'images/lideres/path_to_foto_carla_diaz.jpg' },
            { nombre: 'Julio César', apellido: 'Riquelme', foto: 'images/lideres/path_to_foto_julio_cesar_riquelme.jpg' },
            { nombre: 'Isaías', apellido: 'Gajardo', foto: 'images/lideres/path_to_foto_isaias_gajardo.jpg' },
            { nombre: 'David', apellido: 'Barahona', foto: 'images/lideres/path_to_foto_david_barahona.jpg' }
        ],
        imagen: 'images/lideres/path_to_imagen_ohiggins.jpg'
    },
    maule: {
        lideres: [
            { nombre: 'Sofía', apellido: 'Orellana', foto: 'images/lideres/path_to_foto_sofia_orellana.jpg' }
        ],
        coordinadores: [
            { nombre: 'Beatriz', apellido: 'Cárdenas', foto: 'images/lideres/path_to_foto_beatriz_cardenas.jpg' },
            { nombre: 'Juan', apellido: 'Leal', foto: 'images/lideres/path_to_foto_juan_leal.jpg' }
        ],
        imagen: 'images/lideres/path_to_imagen_maule.jpg'
    },
    nuble: {
        lideres: [
            { nombre: 'Dámaris', apellido: 'Valenzuela', foto: 'images/lideres/path_to_foto_damaris_valenzuela.jpg' }
        ],
        coordinadores: [
            { nombre: 'Luisa', apellido: 'Echeverria', foto: 'images/lideres/path_to_foto_luisa_echeverria.jpg' },
            { nombre: 'Evelyn', apellido: 'Muñoz', foto: 'images/lideres/path_to_foto_evelyn_munoz.jpg' },
            { nombre: 'Melisa', apellido: 'Venegas', foto: 'images/lideres/path_to_foto_melisa_venegas.jpg' },
            { nombre: 'Marilen', apellido: 'Valenzuela', foto: 'images/lideres/path_to_foto_marilen_valenzuela.jpg' },
            { nombre: 'Tamara', apellido: 'Ceballos', foto: 'images/lideres/path_to_foto_tamara_ceballos.jpg' }
        ],
        imagen: 'images/lideres/path_to_imagen_nuble.jpg'
    },
    biobio: {
        lideres: [
            { nombre: 'Mickael', apellido: 'Pino', foto: 'images/lideres/path_to_foto_mickael_pino.jpg' },
            { nombre: 'Marianela', apellido: 'Salazar', foto: 'images/lideres/path_to_foto_marianela_salazar.jpg' }
        ],
        coordinadores: [
            { nombre: 'Barbara', apellido: 'Contreras', foto: 'images/lideres/path_to_foto_barbara_contreras.jpg' },
            { nombre: 'Leandro', apellido: 'Monsalve', foto: 'images/lideres/path_to_foto_leandro_monsalve.jpg' },
            { nombre: 'Gabriela', apellido: 'Horta', foto: 'images/lideres/path_to_foto_gabriela_horta.jpg' },
            { nombre: 'Lucas', apellido: 'Ceballos', foto: 'images/lideres/path_to_foto_lucas_ceballos.jpg' }
        ],
        imagen: 'images/lideres/path_to_imagen_biobio.jpg'
    },
    los_lagos: {
        lideres: [
            { nombre: 'Andrés', apellido: 'Tapia', foto: 'images/lideres/path_to_foto_andres_tapia.jpg' }
        ],
        coordinadores: [],
        imagen: 'images/lideres/path_to_imagen_los_lagos.jpg'
    }
};

const directorio = [
    { nombre: 'Jairo Isaías', apellido: 'Valenzuela Fuentes', cargo: 'Líder General', foto: 'images/lideres/path_to_foto_jairo_valenzuela.jpg' },
    { nombre: 'Génesis Constanza', apellido: 'Vallejos Zapata', cargo: 'Coordinadora General', foto: 'images/lideres/path_to_foto_genesis_vallejos.jpg' },
    { nombre: 'Andrés Saúl', apellido: 'Tapia Loncón', cargo: 'Director de Actividades', foto: 'images/lideres/path_to_foto_andres_tapia.jpg' },
    { nombre: 'Rebeca Ester', apellido: 'González Alarcón', cargo: 'Secretaria General', foto: 'images/lideres/path_to_foto_rebeca_gonzalez.jpg' },
    { nombre: 'Karen Andrea', apellido: 'Millapan Jara', cargo: 'Directora de Finanzas', foto: 'images/lideres/path_to_foto_karen_millapan.jpg' },
    { nombre: 'Luciano Paolo', apellido: 'Moya Cárdenas', cargo: 'Director de Finanzas', foto: 'images/lideres/path_to_foto_luciano_moya.jpg' },
    { nombre: 'Ignacia Belén', apellido: 'Madrid Venegas', cargo: 'Directora de Comunicaciones', foto: 'images/lideres/path_to_foto_ignacia_madrid.jpg' },
    { nombre: 'Milsa Daniela', apellido: 'Morales Lillo', cargo: 'Directora de Misiones', foto: 'images/lideres/path_to_foto_milsa_morales.jpg' },
    { nombre: 'Alejandro Esteban', apellido: 'Muñoz Cid', cargo: 'Director de Misiones', foto: 'images/lideres/path_to_foto_alejandro_munoz.jpg' },
    { nombre: 'Raquel Elizabeth', apellido: 'Marambio Valencia', cargo: 'Directora', foto: 'images/lideres/path_to_foto_raquel_marambio.jpg' },
    { nombre: 'Aron Antonio', apellido: 'Vicuña Rojas', cargo: 'Director', foto: 'images/lideres/path_to_foto_aron_vicuna.jpg' },
    { nombre: 'Marianela Inés', apellido: 'Salazar Velasquez', cargo: 'Directora', foto: 'images/lideres/path_to_foto_marianela_salazar.jpg' },
    { nombre: 'Mickael Alejandro José', apellido: 'Pino Cortés', cargo: 'Director', foto: 'images/lideres/path_to_foto_mickael_pino.jpg' }
];

function mostrarDirectorio() {
    const equipoInfo = document.getElementById('equipoInfo');
    let html = '<h3>Directorio</h3><ul>';
    directorio.forEach(miembro => {
        html += `<li>
                    <img src="${miembro.foto}" alt="${miembro.nombre} ${miembro.apellido}" class="foto">
                    <span><strong>${miembro.nombre}</strong></span>
                    <span><strong>${miembro.apellido}</strong></span>
                    <span>${miembro.cargo}</span>
                 </li>`;
    });
    html += '</ul>';
    equipoInfo.innerHTML = html;

    // Añadir rebote a las nuevas imágenes
    const nuevasImagenes = document.querySelectorAll('#equipoInfo .foto');
    nuevasImagenes.forEach(img => {
        img.classList.add('rebote');
        setTimeout(() => {
            img.classList.remove('rebote');
        }, 1000); // Duración del rebote en milisegundos
    });
}

function mostrarDetalles(region, event) {
    const equipoInfo = document.getElementById('equipoInfo');
    const regionInfo = document.getElementById('regionInfo');
    const nombreRegion = document.getElementById('nombreRegion');
    const lupaContainer = document.getElementById('lupa-container');

    const equipo = equipos[region];
    let regionNombreCompleto;
    switch(region) {
        case 'coquimbo':
            regionNombreCompleto = "de Coquimbo";
            break;
        case 'valparaiso':
            regionNombreCompleto = "de Valparaíso";
            break;
        case 'metropolitana':
            regionNombreCompleto = "Metropolitana";
            break;
        case 'ohiggins':
            regionNombreCompleto = "de O'Higgins";
            break;
        case 'maule':
            regionNombreCompleto = "del Maule";
            break;
        case 'nuble':
            regionNombreCompleto = "de Ñuble";
            break;
        case 'biobio':
            regionNombreCompleto = "del Biobío";
            break;
        case 'los_lagos':
            regionNombreCompleto = "de Los Lagos";
            break;
    }

    let html = `<h3>Equipo Región ${regionNombreCompleto}</h3>`;
    html += '<h4>Líderes:</h4><ul>';
    equipo.lideres.forEach(lider => {
        html += `<li><img src="${lider.foto}" alt="${lider.nombre} ${lider.apellido}" class="foto"><span>${lider.nombre} ${lider.apellido}</span></li>`;
    });
    html += '</ul><h4>Coordinadores:</h4><ul>';
    equipo.coordinadores.forEach(coordinador => {
        html += `<li><img src="${coordinador.foto}" alt="${coordinador.nombre} ${coordinador.apellido}" class="foto"><span>${coordinador.nombre} ${coordinador.apellido}</span></li>`;
    });
    html += '</ul>';
    html += `<div class="imagen-region-container"><img src="${equipo.imagen}" alt="Imagen de ${regionNombreCompleto}" class="imagen-region"></div>`;
    equipoInfo.innerHTML = html;

    nombreRegion.textContent = regionNombreCompleto;

    regionInfo.style.display = 'none';

    // Crear lupa
    const lupa = document.createElement('div');
    lupa.className = 'lupa';
    lupa.style.left = `${event.pageX - -50}px`;
    lupa.style.top = `${event.pageY - 250}px`;
    const img = document.createElement('img');
    img.src = equipo.imagen;
    lupa.appendChild(img);
    lupaContainer.appendChild(lupa);
    lupa.style.display = 'block';
    lupa.style.transform = 'scale(1)';

    // Remover lupa al quitar el cursor
    lupa.addEventListener('mouseout', () => {
        lupa.style.transform = 'scale(0)';
        setTimeout(() => {
            lupa.remove();
        }, 300);
    });

    // Añadir rebote a las nuevas imágenes
    const nuevasImagenes = document.querySelectorAll('#equipoInfo .foto');
    nuevasImagenes.forEach(img => {
        img.classList.add('rebote');
        setTimeout(() => {
            img.classList.remove('rebote');
        }, 1000); // Duración del rebote en milisegundos
    });
}

function ocultarDetalles() {
    const lupaContainer = document.getElementById('lupa-container');
    lupaContainer.innerHTML = '';
}
