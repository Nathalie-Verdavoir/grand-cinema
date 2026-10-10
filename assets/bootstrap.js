import { Application } from '@hotwired/stimulus';
import FilmCrudController from './controllers/search-film_controller.js';
import SeancePlannerController from './controllers/seance-planner_controller.js';
import './styles/tokens.css';
import './styles/admin.css';

const app = Application.start();
app.register('search-film', FilmCrudController);
app.register('seance-planner', SeancePlannerController);