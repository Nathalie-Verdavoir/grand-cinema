import { Application } from '@hotwired/stimulus';
import FilmCrudController from './controllers/search-film_controller.js';
import './styles/admin.css';

const app = Application.start();
app.register('search-film', FilmCrudController);