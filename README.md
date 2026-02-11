# BACKEND

Fare il clone del progetto da GitHub comando GIT CLONE https://github.com/pellicoalex/goalfy-back.git

## Avvio Backend

## 1 Posizionarsi nella cartella del backend del progetto:

 **goalfy-back**.

## 2 Installare tutte le dipendenze PHP tramite Composer:

composer install

## 3a Avviare il server di sviluppo backend utilizzando il server PHP integrato:

php -S localhost:8000 -t public

## 3b In alternativa, se disponibile, è possibile avviare il backend tramite lo script Composer:

composer dev

Una volta avviato, il backend sarà disponibile all’indirizzo:

http://localhost:8000


## Nome del db
goalfy-DB


## Struttura del Progetto

```
goalfy-backend/
├── config/
│   ├── database.php              # Configurazione connessione PostgreSQL
│   └── cors.php                  # Configurazione CORS

├── public/
│   └── index.php                 # Entry point dell'applicazione (PHP built-in server)

├── routes/
│   ├── teams.php                 # Route squadre
│   ├── players.php               # Route giocatori
│   ├── tournaments.php           # Route tornei
│   ├── matches.php               # Route match
│   └── index.php                 # Registrazione globale route

├── src/
│   ├── bootstrap.php             # Bootstrap applicazione (autoload, config, router)

│   ├── Database/
│   │   ├── DB.php                # Wrapper PDO (select, insert, update, delete, transaction)
│   │   └── JSONDB.php            # Supporto eventuale driver JSON

│   ├── Models/
│   │   ├── BaseModel.php         # Classe base ORM custom
│   │   ├── Team.php              # Model Team
│   │   ├── Player.php            # Model Player
│   │   ├── Tournament.php        # Model Tournament
│   │   ├── MatchModel.php        # Model Match
│   │   └── TournamentParticipant.php  # Pivot many-to-many

│   ├── Services/
│   │   └── TournamentService.php # Logica business (bracket, risultati, avanzamento)

│   ├── Traits/
│   │   ├── HasRelations.php      # Gestione relazioni (hasMany, belongsTo)
│   │   └── WithValidate.php      # Sistema validazione custom

│   └── Utils/
│       ├── Request.php           # Gestione input HTTP
│       ├── Response.php          # Gestione risposte JSON
│       └── UploadHelper.php      # Upload immagini (avatar, logo)

├── composer.json                 # Dipendenze Composer
├── composer.lock
└── README.md                     # Documentazione progetto

```


## Organizzazione routes


- teams

get /teams
lista di tutte le squadre 

get /teams/ready
lista squadre con esattamente 5 giocatori

get /teams/{id}
recupero singola squadra con join dei players

post /teams
creazione di una nuova squadra

put /teams/{id}
modifica della squadra

delete /teams/{id}
eliminazione squadra (soft delete tramite funzione DB)

post /teams/{id}/logo
upload del logo squadra (png/jpg/webp max 3MB)

get /teams/{id}/players
recupero lista giocatori della squadra

- players

get /players
lista di tutti i giocatori (con filtro opzionale ?team_id=)

get /players/{id}
recupero singolo giocatore con statistiche dinamiche (goal, assist, presenze)

post /players
creazione di un nuovo giocatore

put /players/{id}
modifica del giocatore

delete /players/{id}
eliminazione del giocatore

post /players/{id}/avatar
upload avatar giocatore

- tournaments

get /tournaments
lista tornei con:

winner_name

has_matches

has_results

get /tournaments/{id}
recupero singolo torneo con partecipanti

post /tournaments
creazione del torneo (status default: draft)

patch /tournaments/{id}
modifica torneo (solo se non completed e senza risultati)

delete /tournaments/{id}
eliminazione torneo (solo se non completed e senza risultati)

- tournament participants

post /tournaments/{id}/participants
inserimento esattamente 8 squadre nel torneo
(bloccato se esistono match già generati)

get /tournaments/{id}/participants
recupero squadre partecipanti al torneo

- bracket

post /tournaments/{id}/generate-bracket
genera automaticamente:

4 quarti

2 semifinali

1 finale

aggiorna stato torneo → ongoing

get /tournaments/{id}/bracket
recupero completo del bracket con:

team A e team B

winner

logo squadre

goal events

- matches

put /matches/{id}/result
salvataggio risultato match:

aggiorna punteggio

salva goal events

salva presenze

avanza vincitore

completa torneo se finale

get /matches/{id}/goal-events
recupero goal events del match

- statistiche torneo

get /tournaments/{id}/goal-events
recupero storico completo goal del torneo

get /tournaments/{id}/players
recupero tutti i giocatori che hanno partecipato al torneo


## Organizzazione models

- Team

inizializzazione variabili

validationRules

relazione hasMany Players

- Player

inizializzazione variabili

validationRules

funzione fullName()

relazione belongsTo Team

- Tournament

inizializzazione variabili

validationRules

relazione hasMany Participants

relazione hasMany Matches

relazione belongsTo Winner Team

- MatchModel

inizializzazione variabili

gestione round (1=QF, 2=SF, 3=F)

status (waiting | scheduled | played)

collegamento match successivo (next_match_id, next_slot)

relazioni con Tournament e Team

- TournamentParticipant

tabella pivot torneo-squadra

validationRules

relazione belongsTo Tournament

relazione belongsTo Team

## Organizzazione services(azioni complesse nel torneo)

- TournamentService

generateBracket8

verifica 8 squadre

crea 7 match

collega bracket

aggiorna stato torneo

setMatchResult

valida punteggi

blocca match già played

salva presenze

salva goal

avanza vincitore

completa torneo

getPlayerAggregatedStats

calcolo presenze

calcolo goal

calcolo assist


## Tecnologie e librerie utilizzate
- PHP 8.x
- PostgreSQL 15.x
- PDO
- Pecee SimpleRouter 5.x
- Composer 2.x
- PHP built-in server
- Architettura (Routes → Services → Models)
- CORS custom configuration
- Upload immagini (filesystem locale – public/uploads)

# Progetto     

Lo Sport scelto è il calco a 5 e il nome della piattaforma è GOALFY ispirato a SPOTIFY colosso della musica ma in ottica del futsal quindi da li il nome Goalfy da goal che rappresenta l'obbiettivo finale del medisimo sport. Il Tournament Manager consiste nella creazione di team, associazione dei team ai tornei creati, vedere i tornei in draft, in corso e conclusi tramite la pagina tornei e si può vedere lo storico tramite la pagina storico con il percorso della squadra vincente in tutte le sue partite fino alla conquista del tornei con MVP, Capocannoniere e miglior Portiere. Ogni Giocatore ha la sua scheda tecnica con info personali, squadra di appartenenza, e numeri del torneo(goal, presenze assist).

Il gestionale permette di:

- Creare squadre (max 5 giocatori)
- Assegnare ruoli futsal (GOALKEEPER, FIXO, ALA, PIVO, UNIVERSAL)
- Creare tornei a 8 squadre
- Generare automaticamente il bracket
- Salvare goal e assist per ogni match
- Avanzare automaticamente il vincitore
- Calcolare statistiche aggregate
- Gestire upload immagini (logo e avatar)
- Gestire stato torneo: draft → ongoing → completed


## Buon divertimento con GOALFY Tournament Manager